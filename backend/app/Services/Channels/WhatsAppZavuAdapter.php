<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\Contracts\Channels\ChannelAdapter;
use App\Contracts\Channels\MemberChannelHost;
use App\DTOs\Channels\InboundMessage;
use App\Models\Community;
use App\Models\User;
use App\Services\AI\AiServiceClient;
use App\Services\Knowledge\CitationRevalidator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Official WhatsApp path via Zavu BSP (Cloud API underneath).
 * Spikes (WhatsApp Web / Telegram) stay separate and env-gated.
 */
final class WhatsAppZavuAdapter implements ChannelAdapter, MemberChannelHost
{
    public function __construct(
        private readonly AiServiceClient $aiClient,
        private readonly CitationRevalidator $citationRevalidator,
        private readonly ChannelConversationService $conversation,
        private readonly SpikeEscalationNotifier $escalationNotifier,
        private readonly ChannelListenGate $listenGate,
        private readonly ChannelCommandAccess $commandAccess,
        private readonly MemberChannelPipeline $memberPipeline,
        private readonly VoiceNoteNormalizer $voiceNormalizer,
        private readonly ImageNoteNormalizer $imageNormalizer,
        private readonly WhatsAppOutboundFormatter $whatsAppOutbound,
        private readonly ChannelSwipeQuoteContext $swipeQuote,
    ) {}

    public function handleInbound(InboundMessage $message): ?string
    {
        if ($this->memberPipeline->isAdminImportWithAttachment($this, $message)) {
            $reply = $this->memberPipeline->handleAdminImportWithAttachment($this, $message);

            return $this->whatsAppOutbound->format($reply);
        }

        $voice = $this->voiceNormalizer->normalize($message);
        if (($voice['error'] ?? null) !== null) {
            return $this->whatsAppOutbound->format((string) $voice['error']);
        }
        $message = $voice['message'];

        $image = $this->imageNormalizer->normalize($message);
        if (($image['error'] ?? null) !== null) {
            return $this->whatsAppOutbound->format((string) $image['error']);
        }
        $message = $image['message'];

        $reply = $this->dispatchInbound($message);
        if ($reply === null || trim($reply) === '') {
            return $reply;
        }

        return $this->whatsAppOutbound->format($reply);
    }

    public function channelName(): string
    {
        return 'whatsapp_zavu';
    }

    private function dispatchInbound(InboundMessage $message): ?string
    {
        if (trim($message->text) === '') {
            return null;
        }

        $upper = strtoupper($message->text);
        $isAdmin = $this->commandAccess->isAdmin(
            $this->channelName(),
            $message->externalUserId,
            array_merge(is_array($message->raw) ? $message->raw : [], ['text' => $message->text]),
        );

        if (str_starts_with($upper, 'JOIN-') || str_starts_with($upper, '/JOIN')) {
            return $this->handleJoin($message);
        }

        $adminReply = $this->memberPipeline->tryAdminInbound($this, $message, $isAdmin);
        if ($adminReply !== null) {
            return $adminReply;
        }

        $slashReply = $this->memberPipeline->trySlashInbound($this, $message, $isAdmin);
        if ($slashReply !== null) {
            return $slashReply;
        }

        if ($this->listenGate->startsWithSlashCommand($message->text, 'ask')) {
            return $this->handleMemberAsk($message);
        }

        $adminIndexAck = $isAdmin ? $this->memberPipeline->tryAdminAutoIndex($this, $message) : null;

        $community = $this->resolveLinkedCommunity($message);
        $scope = $community?->description;
        $priorTurns = $this->conversation->turns($this->channelName(), $message->externalUserId);
        $inboundText = $this->swipeQuote->routingText($message);
        $resolved = $this->conversation->resolveInbound($inboundText, $priorTurns, $scope);
        $resolved = $this->applyModelIntentIfNeeded($resolved, $community, $message, $priorTurns);
        $intent = $resolved['intent'];
        $effectiveQuery = $resolved['query'];

        if ($intent === 'clarify') {
            $reply = $this->modelAssistedReply($message, 'social', $community);
            if ($reply === '' || str_contains(mb_strtolower($reply), 'thanks for joining')) {
                $reply = $this->conversation->clarificationReply();
            }
            $this->rememberTurn(
                $message,
                'user',
                $this->conversation->userTurnTextToRemember($message->text, $effectiveQuery),
            );
            $this->rememberTurn($message, 'assistant', $reply);

            return $reply;
        }

        if ($intent === ChannelConversationService::INTENT_CONVERSATIONAL) {
            if ($this->conversation->isBareBotPing($message->text)) {
                $reply = $this->conversation->mentionPingReply(
                    'whatsapp',
                    'whatsapp',
                    $this->chatType($message),
                    $this->memberPhoneForWeb($message),
                );
            } else {
                $reply = $this->modelAssistedReply($message, 'social', $community);
                if ($reply === '') {
                    return $this->handleConversational($message, $community);
                }
            }
            $this->rememberTurn(
                $message,
                'user',
                $this->conversation->userTurnTextToRemember($message->text, $effectiveQuery),
            );
            $this->rememberTurn($message, 'assistant', $reply);

            return $reply;
        }

        if ($intent === ChannelConversationService::INTENT_OUT_OF_SCOPE) {
            $reply = $this->modelAssistedReply($message, 'out_of_scope', $community);
            if ($reply === '') {
                $reply = $this->conversation->outOfScopeReply($community?->name, $community?->description);
            }
            $this->rememberTurn(
                $message,
                'user',
                $this->conversation->userTurnTextToRemember($message->text, $effectiveQuery),
            );
            $this->rememberTurn($message, 'assistant', $reply);

            return $reply;
        }

        if ($intent === ChannelConversationService::INTENT_PERSONAL_HELP) {
            return $this->handlePersonalHelp($message, $community);
        }

        $askReply = $this->handleAsk(
            $message,
            $effectiveQuery,
            (string) ($resolved['link_mode'] ?? 'none'),
            (string) ($resolved['link_focus'] ?? 'na'),
            filter_var($resolved['needs_temporal_resolution'] ?? false, FILTER_VALIDATE_BOOLEAN),
            $priorTurns,
        );

        return $askReply ?? $adminIndexAck;
    }

    private function handleMemberAsk(InboundMessage $message): string
    {
        $body = $this->listenGate->slashCommandBody($message->text, 'ask');

        if ($body === '') {
            $reply = "Send your question like this:\n"
                ."/ask When is the next UniPods session?\n\n"
                .'If I already know the answer, I\'ll reply right away. '
                .'Otherwise an admin will see it and can reply from their side.';
            $this->conversation->remember($this->channelName(), $message->externalUserId, 'user', $message->text);
            $this->conversation->remember($this->channelName(), $message->externalUserId, 'assistant', $reply);

            return $reply;
        }

        $answered = $this->tryAnswerMemberAsk($message, $body);
        if ($answered !== null) {
            $this->conversation->remember($this->channelName(), $message->externalUserId, 'user', $message->text);
            $this->conversation->remember($this->channelName(), $message->externalUserId, 'assistant', $answered);

            return $answered;
        }

        $community = $this->resolveLinkedCommunity($message);
        $communityId = $community?->id
            ?? (string) config('whatsapp_zavu.default_community_id', '');

        $result = $this->escalationNotifier->handleMemberAsk(
            channel: $this->channelName(),
            from: $message->externalUserId,
            question: $body,
            communityId: (string) $communityId,
            communityName: $community?->name,
            fromName: null,
        );

        $reply = (string) ($result['reply'] ?? 'Done.');
        $this->conversation->remember($this->channelName(), $message->externalUserId, 'user', $message->text);
        $this->conversation->remember($this->channelName(), $message->externalUserId, 'assistant', $reply);

        return $reply;
    }

    /**
     * Attempt a grounded answer for /ask. Null means escalate to an admin.
     */
    private function tryAnswerMemberAsk(InboundMessage $message, string $question): ?string
    {
        $link = Cache::get($this->linkCacheKey($message->externalUserId));
        $user = $this->resolveUser();
        $communityId = is_array($link)
            ? (string) ($link['community_id'] ?? '')
            : (string) config('whatsapp_zavu.default_community_id');

        if ($user === null || $communityId === '' || ! $user->belongsToCommunity($communityId)) {
            return null;
        }

        $community = Community::query()->find($communityId);
        if ($community === null) {
            return null;
        }

        if ($this->conversation->isClearlyOutOfScope($question)) {
            return null;
        }

        $body = $this->swipeQuote->foldIntoKnowledgeQuery($message, $body);

        $priorTurns = $this->conversation->turns($this->channelName(), $message->externalUserId);
        $query = $this->conversation->buildKnowledgeQuery($body, $priorTurns);

        $result = $this->aiClient->askGroundedQuestion(
            query: $query,
            tenantId: $community->tenant_id,
            communityIds: [$communityId],
            targetLanguage: null,
            linkMode: 'none',
        );
        $result = $this->citationRevalidator->revalidate($user, $result);

        $answer = trim((string) $result->answer);
        if ($answer === '') {
            return null;
        }

        $reason = strtolower((string) ($result->escalationReason ?? ''));
        if (str_contains($reason, 'ai service')
            || str_contains($reason, 'unreachable')
            || str_contains($reason, 'timed out')) {
            return null;
        }

        $plain = $this->plainText($answer);
        $plain = $this->conversation->tidyMemberAnswer($plain);

        return trim($plain) !== '' ? $plain : null;
    }

    private function handleJoin(InboundMessage $message): string
    {
        $raw = trim($message->text);
        if (str_starts_with(strtoupper($raw), '/JOIN')) {
            $raw = trim(substr($raw, 5));
        }
        if (str_starts_with(strtoupper($raw), 'JOIN-')) {
            $token = trim(substr($raw, 5));
        } else {
            $token = trim($raw);
        }

        $communityId = Cache::pull('wa_zavu_join:'.strtolower($token));

        if ($communityId === null || ! Community::query()->whereKey($communityId)->exists()) {
            return 'JOIN failed: invalid or expired token. Ask an admin for a new JOIN link.';
        }

        $user = $this->resolveUser();
        if ($user === null) {
            return 'JOIN failed: no channel user configured (set WHATSAPP_ZAVU_DEFAULT_USER_EMAIL).';
        }

        Cache::forever($this->linkCacheKey($message->externalUserId), [
            'user_id' => $user->id,
            'community_id' => $communityId,
        ]);

        $reply = "Welcome! You're linked now. Ask me anything about this community whenever you're ready.";
        $this->conversation->remember($this->channelName(), $message->externalUserId, 'user', $message->text);
        $this->conversation->remember($this->channelName(), $message->externalUserId, 'assistant', $reply);

        return $reply;
    }

    private function handleConversational(InboundMessage $message, ?Community $community): string
    {
        $ctx = $this->conversation->communityModelContext($community?->name, $community?->description);
        $modelReply = $this->aiClient->conversationalReply(
            message: $message->text,
            mode: 'social',
            communityName: $ctx['name'],
            communityScope: $ctx['scope'],
        );

        $reply = trim($modelReply);
        if ($reply === '') {
            $reply = $this->conversation->conversationalReply(
                $message->text,
                'whatsapp',
                'whatsapp',
                'private',
                $this->memberPhoneForWeb($message),
            );
        }

        $this->conversation->remember($this->channelName(), $message->externalUserId, 'user', $message->text);
        $this->conversation->remember($this->channelName(), $message->externalUserId, 'assistant', $reply);

        return $reply;
    }

    /**
     * @param  list<array{role: string, text: string, at?: string}>  $priorTurns
     */
    private function handleAsk(
        InboundMessage $message,
        ?string $effectiveQuery = null,
        string $linkMode = 'none',
        string $linkFocus = 'na',
        bool $needsTemporal = false,
        array $priorTurns = [],
    ): string {
        $link = Cache::get($this->linkCacheKey($message->externalUserId));
        $user = $this->resolveUser();
        $communityId = is_array($link)
            ? (string) ($link['community_id'] ?? '')
            : (string) config('whatsapp_zavu.default_community_id');

        if ($user === null) {
            return "Sorry, I can't look that up yet. Please ask your admin to finish setting me up.";
        }

        if ($communityId === '') {
            return "You're not linked to a community yet. Ask an admin for a JOIN link, then send it here.";
        }

        if (! $user->belongsToCommunity($communityId)) {
            return "It looks like you're not a member of that community in "
                .$this->conversation->botDisplayName().' yet. An admin can add you.';
        }

        $community = Community::query()->find($communityId);
        if ($community === null) {
            return "Sorry, I couldn't find that community. Could you double-check with your admin?";
        }

        $allowedLink = ['none', 'recordings', 'meetings', 'assets'];
        if (! in_array($linkMode, $allowedLink, true)) {
            $linkMode = 'none';
        }

        $question = trim((string) ($effectiveQuery ?? $message->text));
        if ($question === '') {
            $question = $message->text;
        }

        $question = $this->swipeQuote->foldIntoKnowledgeQuery($message, $question);

        if ($priorTurns === []) {
            $priorTurns = $this->conversation->turns($this->channelName(), $message->externalUserId);
        }
        $query = $this->conversation->buildKnowledgeQuery($question, $priorTurns);
        $query = $this->enrichKnowledgeQueryWithTemporalPreflight($query, $question, $needsTemporal, $priorTurns);

        $allowedFocus = ['one', 'many', 'na'];
        if (! in_array($linkFocus, $allowedFocus, true)) {
            $linkFocus = 'na';
        }
        if ($linkMode === 'none') {
            $linkFocus = 'na';
        } elseif ($linkFocus === 'na') {
            $linkFocus = 'many';
        }

        $result = $this->aiClient->askGroundedQuestion(
            query: $query,
            tenantId: $community->tenant_id,
            communityIds: [$communityId],
            targetLanguage: null,
            linkMode: $linkMode,
            linkFocus: $linkFocus,
        );

        $result = $this->citationRevalidator->revalidate($user, $result);

        if ($result->answer === '') {
            $fromName = null;
            $this->escalationNotifier->escalate([
                'question' => $question,
                'from' => $message->externalUserId,
                'from_name' => $fromName,
                'community_id' => $communityId,
                'community_name' => $community->name,
                'reason' => (string) ($result->escalationReason ?? 'insufficient_evidence'),
                'channel' => $this->channelName(),
            ]);

            $reply = "I don't have a solid answer for that yet.\n\n"
                ."I've passed it along, and I'll follow up once I have one. "
                .'No need to keep checking or asking again.';
            $this->conversation->remember($this->channelName(), $message->externalUserId, 'user', $message->text);
            $this->conversation->remember($this->channelName(), $message->externalUserId, 'assistant', $reply);

            return $reply;
        }

        // No English "(From …)" footer — it mixes languages with non-English replies (web spike parity).
        $answer = $this->conversation->tidyMemberAnswer(trim((string) $result->answer));

        $this->conversation->remember(
            $this->channelName(),
            $message->externalUserId,
            'user',
            $this->conversation->userTurnTextToRemember($message->text, $question),
        );
        $this->conversation->remember($this->channelName(), $message->externalUserId, 'assistant', $answer);

        return $answer;
    }

    /**
     * @param  list<array{role: string, text: string, at?: string}>  $priorTurns
     */
    private function enrichKnowledgeQueryWithTemporalPreflight(
        string $query,
        string $question,
        bool $needsTemporal,
        array $priorTurns,
    ): string {
        if (! $needsTemporal) {
            return $query;
        }

        $priorQuestion = $this->conversation->lastRetrievableUserQuestion($priorTurns);
        $plan = $this->aiClient->conversationTemporalPlan(
            message: $question,
            priorQuestion: $priorQuestion,
        );
        if ($plan === null || ! ($plan['needs_resolution'] ?? false)) {
            return $query;
        }

        $ctx = trim((string) ($plan['temporal_context'] ?? ''));
        if ($ctx === '') {
            return $query;
        }

        return $query."\n\n[TEMPORAL GROUNDING]\n".$ctx;
    }

    /**
     * @param  array{intent: string, query: string, link_mode?: string}  $resolved
     * @param  list<array{role: string, text: string}>  $priorTurns
     * @return array{intent: string, query: string, link_mode: string, link_focus?: string, needs_temporal_resolution?: bool}
     */
    private function applyModelIntentIfNeeded(
        array $resolved,
        ?Community $community,
        InboundMessage $message,
        array $priorTurns = [],
    ): array {
        $query = (string) ($resolved['query'] ?? '');
        if (! $this->conversation->needsModelRouting($query)) {
            if (($resolved['link_mode'] ?? 'none') === 'none') {
                $resolved['link_mode'] = $this->conversation->inferLinkMode($query);
            }

            return $resolved;
        }

        $ctx = $this->conversation->communityModelContext($community?->name, $community?->description);
        $priorQuestion = $this->conversation->lastRetrievableUserQuestion($priorTurns);
        $priorAnswer = $this->conversation->lastKnowledgeAssistantAnswer($priorTurns);
        $replyToBot = filter_var($message->raw['reply_to_bot'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $classified = $this->aiClient->classifyConversationIntent(
            message: $query,
            communityName: $ctx['name'],
            communityScope: $ctx['scope'],
            priorQuestion: $priorQuestion,
            priorAnswerExcerpt: $priorAnswer,
            replyToBot: $replyToBot,
        );

        return $this->conversation->mergeModelClassification(
            $resolved,
            $classified,
            $community?->description,
            $priorTurns,
            $replyToBot,
        );
    }

    private function modelAssistedReply(InboundMessage $message, string $mode, ?Community $community): string
    {
        $ctx = $this->conversation->communityModelContext($community?->name, $community?->description);

        $reply = trim($this->aiClient->conversationalReply(
            message: $message->text,
            mode: $mode,
            communityName: $ctx['name'],
            communityScope: $ctx['scope'],
        ));

        if (
            $reply !== ''
            && $mode === 'out_of_scope'
            && $this->conversation->shouldAppendEnglishAskHint($reply, $message->text)
        ) {
            $reply = rtrim($reply)."\n\n".$this->conversation->askAdminHint('whatsapp');
        }

        return $reply;
    }

    private function handlePersonalHelp(InboundMessage $message, ?Community $community): string
    {
        $reply = $this->modelAssistedReply($message, 'personal_help', $community);
        if ($reply === '') {
            $reply = $this->conversation->personalHelpFallbackReply();
        }
        $this->rememberTurn($message, 'user', $message->text);
        $this->rememberTurn($message, 'assistant', $reply);

        return $reply;
    }

    private function rememberTurn(InboundMessage $message, string $role, string $text): void
    {
        $this->conversation->remember($this->channelName(), $message->externalUserId, $role, $text);
    }

    public function chatType(InboundMessage $message): string
    {
        $type = strtolower(trim((string) ($message->raw['chat_type'] ?? 'private')));

        return $type !== '' ? $type : 'private';
    }

    /**
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    public function adminActor(InboundMessage $message): array
    {
        $phone = trim((string) ($message->raw['from_phone'] ?? $message->externalUserId));

        return [$this->channelName(), $phone, null];
    }

    public function knowledgeImportUriPrefix(): string
    {
        return 'whatsapp-zavu://import/';
    }

    public function knowledgeShareUriPrefix(): string
    {
        return 'whatsapp-zavu://share/';
    }

    public function resolveLinkedCommunity(InboundMessage $message): ?Community
    {
        $link = Cache::get($this->linkCacheKey($message->externalUserId));
        $communityId = is_array($link)
            ? (string) ($link['community_id'] ?? '')
            : (string) config('whatsapp_zavu.default_community_id');

        if ($communityId === '') {
            return null;
        }

        return Community::query()->find($communityId);
    }

    public function resolveUser(): ?User
    {
        $email = (string) config('whatsapp_zavu.default_user_email');
        if ($email === '') {
            return null;
        }

        return User::query()->where('email', $email)->first();
    }

    private function linkCacheKey(string $externalUserId): string
    {
        return 'wa_zavu_link:'.sha1($externalUserId);
    }

    public function memberPhoneForWeb(InboundMessage $message): ?string
    {
        return app(\App\Services\WebChat\WebChatMemberPhone::class)->normalize(
            (string) ($message->raw['from_phone'] ?? $message->externalUserId),
        );
    }

    private function plainText(string $text): string
    {
        $text = str_replace(["\u{2014}", "\u{2013}"], ['-', '-'], $text);
        $text = preg_replace('/\*\*(.+?)\*\*/us', '$1', $text) ?? $text;
        $text = preg_replace('/\*(.+?)\*/us', '$1', $text) ?? $text;
        $text = preg_replace('/_(.+?)_/us', '$1', $text) ?? $text;

        return trim($text);
    }

    public static function mintJoinToken(string $communityId, ?int $ttlHours = null): string
    {
        $ttl = $ttlHours ?? (int) config('whatsapp_zavu.join_token_ttl_hours', 72);
        $token = Str::lower((string) Str::ulid());
        Cache::put('wa_zavu_join:'.$token, $communityId, now()->addHours($ttl));

        return 'JOIN-'.$token;
    }

    public static function waMeLink(string $joinMessage): ?string
    {
        $phone = preg_replace('/\D+/', '', (string) config('whatsapp_zavu.phone_number')) ?? '';
        if ($phone === '') {
            return null;
        }

        return 'https://wa.me/'.$phone.'?text='.rawurlencode($joinMessage);
    }
}
