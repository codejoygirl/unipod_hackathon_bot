<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\Contracts\Channels\ChannelAdapter;
use App\DTOs\Channels\InboundMessage;
use App\Models\Community;
use App\Models\User;
use App\Services\AI\AiServiceClient;
use App\Services\Knowledge\CitationRevalidator;
use App\Services\Knowledge\KnowledgeLifecycleService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Official WhatsApp path via Zavu BSP (Cloud API underneath).
 * Spikes (WhatsApp Web / Telegram) stay separate and env-gated.
 */
final class WhatsAppZavuAdapter implements ChannelAdapter
{
    public function __construct(
        private readonly AiServiceClient $aiClient,
        private readonly CitationRevalidator $citationRevalidator,
        private readonly KnowledgeLifecycleService $lifecycle,
        private readonly ChannelConversationService $conversation,
        private readonly SpikeEscalationNotifier $escalationNotifier,
    ) {}

    public function channelName(): string
    {
        return 'whatsapp_zavu';
    }

    public function handleInbound(InboundMessage $message): ?string
    {
        if ($message->text === '') {
            return null;
        }

        $upper = strtoupper($message->text);

        if (str_starts_with($upper, 'JOIN-') || str_starts_with($upper, '/JOIN')) {
            return $this->handleJoin($message);
        }

        if (str_starts_with($upper, 'SHARE')
            || str_starts_with($upper, '/SHARE')
            || str_starts_with($upper, 'IMPORT')
            || str_starts_with($upper, '/IMPORT')
            || str_starts_with($upper, 'EXPORT')
            || str_starts_with($upper, '/EXPORT')) {
            return $this->handleShare($message);
        }

        if (str_starts_with($upper, 'ASK') || str_starts_with($upper, '/ASK')) {
            return $this->handleMemberAsk($message);
        }

        $community = $this->resolveLinkedCommunity($message);
        $scope = $community?->description;
        $priorTurns = $this->conversation->turns($this->channelName(), $message->externalUserId);
        $resolved = $this->conversation->resolveInbound($message->text, $priorTurns, $scope);
        $intent = $resolved['intent'];
        $effectiveQuery = $resolved['query'];

        if ($intent === 'clarify') {
            $reply = $this->conversation->clarificationReply();
            $this->conversation->remember($this->channelName(), $message->externalUserId, 'user', $message->text);
            $this->conversation->remember($this->channelName(), $message->externalUserId, 'assistant', $reply);

            return $reply;
        }

        if ($intent === ChannelConversationService::INTENT_CONVERSATIONAL) {
            return $this->handleConversational($message, $community);
        }

        if ($intent === ChannelConversationService::INTENT_OUT_OF_SCOPE) {
            $ctx = $this->conversation->communityModelContext($community?->name, $community?->description);
            $modelReply = $this->aiClient->conversationalReply(
                message: $message->text,
                mode: 'out_of_scope',
                communityName: $ctx['name'],
                communityScope: $ctx['scope'],
            );
            $reply = trim($modelReply);
            if ($reply === '') {
                $reply = $this->conversation->outOfScopeReply($community?->name, $community?->description);
            } elseif (! str_contains(mb_strtolower($reply), '/ask')) {
                $reply = rtrim($reply)."\n\n".$this->conversation->askAdminHint();
            }
            $this->conversation->remember($this->channelName(), $message->externalUserId, 'user', $message->text);
            $this->conversation->remember($this->channelName(), $message->externalUserId, 'assistant', $reply);

            return $this->plainText($reply);
        }

        return $this->handleAsk($message, $effectiveQuery, (string) ($resolved['link_mode'] ?? 'none'));
    }

    private function handleMemberAsk(InboundMessage $message): string
    {
        $body = trim($message->text);
        foreach (['/ASK', 'ASK'] as $prefix) {
            if (str_starts_with(strtoupper($body), $prefix)) {
                $body = trim(substr($body, strlen($prefix)));
                break;
            }
        }

        if ($body === '') {
            $reply = "Send your question like this:\n"
                ."/ask When is the next UniPods session?\n\n"
                .'If I already know the answer, I\'ll reply right away. '
                .'Otherwise an admin will see it and can reply from their side.';
            $this->conversation->remember($this->channelName(), $message->externalUserId, 'user', $message->text);
            $this->conversation->remember($this->channelName(), $message->externalUserId, 'assistant', $reply);

            return $this->plainText($reply);
        }

        $answered = $this->tryAnswerMemberAsk($message, $body);
        if ($answered !== null) {
            $this->conversation->remember($this->channelName(), $message->externalUserId, 'user', $message->text);
            $this->conversation->remember($this->channelName(), $message->externalUserId, 'assistant', $answered);

            return $this->plainText($answered);
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

        return $this->plainText($reply);
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

        $priorTurns = $this->conversation->turns($this->channelName(), $message->externalUserId);
        $query = $this->conversation->buildKnowledgeQuery($question, $priorTurns);

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
        $plain = $this->conversation->stripInternalEvidenceTags($plain);
        if ($result->citations !== []) {
            $source = trim($result->citations[0]->sourceName);
            if ($source !== '') {
                $plain .= "\n\n(From {$source}.)";
            }
        }

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
            $reply = $this->conversation->conversationalReply($message->text);
        }

        $this->conversation->remember($this->channelName(), $message->externalUserId, 'user', $message->text);
        $this->conversation->remember($this->channelName(), $message->externalUserId, 'assistant', $reply);

        return $this->plainText($reply);
    }

    private function handleAsk(
        InboundMessage $message,
        ?string $effectiveQuery = null,
        string $linkMode = 'none',
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

        $priorTurns = $this->conversation->turns($this->channelName(), $message->externalUserId);
        $query = $this->conversation->buildKnowledgeQuery($question, $priorTurns);

        $result = $this->aiClient->askGroundedQuestion(
            query: $query,
            tenantId: $community->tenant_id,
            communityIds: [$communityId],
            targetLanguage: null,
            linkMode: $linkMode,
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
                ."No need to keep checking or asking again.\n\n"
                .$this->conversation->askAdminHint();
            $this->conversation->remember($this->channelName(), $message->externalUserId, 'user', $message->text);
            $this->conversation->remember($this->channelName(), $message->externalUserId, 'assistant', $reply);

            return $this->plainText($reply);
        }

        $answer = $this->plainText($result->answer);
        $answer = $this->conversation->stripInternalEvidenceTags($answer);

        if ($result->citations !== []) {
            $source = trim($result->citations[0]->sourceName);
            if ($source !== '') {
                $answer .= "\n\n(From {$source}.)";
            }
        }

        $this->conversation->remember($this->channelName(), $message->externalUserId, 'user', $message->text);
        $this->conversation->remember($this->channelName(), $message->externalUserId, 'assistant', $answer);

        return $answer;
    }

    private function handleShare(InboundMessage $message): string
    {
        $user = $this->resolveUser();
        $link = Cache::get($this->linkCacheKey($message->externalUserId));
        $communityId = is_array($link)
            ? (string) ($link['community_id'] ?? '')
            : (string) config('whatsapp_zavu.default_community_id');

        if ($user === null || $communityId === '') {
            return 'Link a community with a JOIN token before sharing.';
        }

        if (! $user->belongsToCommunity($communityId)) {
            return 'You are not a member of that community in '.$this->conversation->botDisplayName().'.';
        }

        $community = Community::query()->findOrFail($communityId);
        $body = $message->text;
        foreach (['SHARE', '/SHARE', 'IMPORT', '/IMPORT', 'EXPORT', '/EXPORT'] as $prefix) {
            if (str_starts_with(strtoupper($body), $prefix)) {
                $body = trim(substr($body, strlen($prefix)));
                break;
            }
        }

        if ($body === '') {
            return "Share something the community should know, like:\n"
                ."/share Water off tomorrow morning\n\n"
                .'An admin will review it before '
                .$this->conversation->botDisplayName().' can use it in answers.';
        }

        $source = $this->lifecycle->import($user, [
            'tenant_id' => $community->tenant_id,
            'community_id' => $communityId,
            'name' => 'WhatsApp Zavu share',
            'uri' => 'whatsapp-zavu://share/'.Str::ulid(),
            'source_type' => 'whatsapp',
            'content' => $body,
            'metadata' => [
                'channel' => 'whatsapp_zavu',
                'from' => $message->externalUserId,
            ],
        ]);

        $this->lifecycle->submitForReview($user, $source);

        $notify = $this->escalationNotifier->notifyShareReview(
            channel: $this->channelName(),
            from: $message->externalUserId,
            content: $body,
            knowledgeSourceId: (string) $source->id,
            communityId: $communityId,
            communityName: $community->name,
            fromName: null,
        );

        Log::info('whatsapp_zavu.share_draft', [
            'knowledge_id' => $source->id,
            'notified' => $notify['notified'],
        ]);

        if ($notify['notified']) {
            return $this->conversation->shareQueuedReply(true);
        }

        return $this->conversation->shareQueuedReply(false);
    }

    private function resolveLinkedCommunity(InboundMessage $message): ?Community
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

    private function resolveUser(): ?User
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
