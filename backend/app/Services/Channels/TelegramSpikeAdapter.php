<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\Contracts\Channels\ChannelAdapter;
use App\DTOs\Channels\InboundMessage;
use App\DTOs\GroundedAnswerDTO;
use App\Models\Community;
use App\Models\User;
use App\Services\AI\AiServiceClient;
use App\Services\Knowledge\CitationRevalidator;
use App\Services\Knowledge\KnowledgeLifecycleService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class TelegramSpikeAdapter implements ChannelAdapter
{
    public function __construct(
        private readonly AiServiceClient $aiClient,
        private readonly CitationRevalidator $citationRevalidator,
        private readonly KnowledgeLifecycleService $lifecycle,
        private readonly ChannelConversationService $conversation,
        private readonly SpikeEscalationNotifier $escalationNotifier,
        private readonly ChannelCommandAccess $commandAccess,
        private readonly AdminMessageKnowledgeIndexer $adminIndexer,
        private readonly VoiceNoteNormalizer $voiceNormalizer,
        private readonly ImageNoteNormalizer $imageNormalizer,
        private readonly AdminKnowledgeDesk $knowledgeDesk,
        private readonly ChannelListenGate $listenGate,
    ) {}

    public function channelName(): string
    {
        return 'telegram_spike';
    }

    /**
     * True when Zak should answer this turn (private always; groups only if directed).
     *
     * @param  list<string>  $aliases
     */
    private function isDirectedAtBot(InboundMessage $message, array $aliases, string $chatType): bool
    {
        $replyToBot = filter_var($message->raw['reply_to_bot'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $botMentioned = filter_var($message->raw['bot_mentioned'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || $replyToBot;
        $quoted = trim((string) ($message->raw['quoted_text'] ?? ''));

        $decision = $this->conversation->expectsBotResponse($message->text, [
            'chat_type' => $chatType,
            'reply_to_bot' => $replyToBot,
            'bot_mentioned' => $botMentioned,
            'bot_aliases' => $aliases,
            'quoted_text' => $quoted,
        ]);

        if ($decision === true) {
            return true;
        }
        if ($decision === false) {
            Log::info('telegram_spike.skip_undirected', [
                'from' => $message->externalUserId,
                'chat_type' => $chatType,
                'reply_to_bot' => $replyToBot,
                'bot_mentioned' => $botMentioned,
            ]);

            return false;
        }

        $ai = $this->aiClient->isMessageAddressedToBot(
            $message->text,
            $replyToBot,
            $botMentioned,
            $quoted !== '' ? $quoted : null,
        );
        if ($ai === true) {
            return true;
        }
        if ($ai === false) {
            Log::info('telegram_spike.skip_undirected_ai', [
                'from' => $message->externalUserId,
            ]);

            return false;
        }

        return $replyToBot && mb_strlen(trim($message->text)) >= 2;
    }

    /**
     * Isolate memory per chat + sender so group users never share context.
     */
    private function threadKey(InboundMessage $message): string
    {
        $chatId = trim((string) ($message->raw['chat_id'] ?? ''));
        if ($chatId !== '') {
            return $chatId;
        }

        $chatType = strtolower(trim((string) ($message->raw['chat_type'] ?? 'private')));
        if ($chatType === 'group' || $chatType === 'supergroup') {
            return 'group:unknown:'.$message->externalUserId;
        }

        return 'dm:'.$message->externalUserId;
    }

    private function chatType(InboundMessage $message): string
    {
        $chatType = strtolower(trim((string) ($message->raw['chat_type'] ?? 'private')));

        return in_array($chatType, ['group', 'supergroup'], true) ? 'group' : 'private';
    }

    private function helpText(InboundMessage $message): string
    {
        $chatType = $this->chatType($message);
        $isAdmin = $this->commandAccess->isAdmin(
            $this->channelName(),
            $message->externalUserId,
            is_array($message->raw) ? $message->raw : [],
        );
        // Admin commands only in private DM — never expose them in group /help.
        $showAdmin = $isAdmin && $chatType === 'private';

        return $this->conversation->helpTextFor(
            'plain',
            'telegram',
            $showAdmin,
            $chatType,
            $this->memberPhoneForWeb($message),
        );
    }

    private function memberPhoneForWeb(?InboundMessage $message): ?string
    {
        if ($message === null || $this->chatType($message) !== 'private') {
            return null;
        }

        $raw = is_array($message->raw) ? $message->raw : [];

        return app(\App\Services\WebChat\WebChatMemberPhone::class)
            ->normalize((string) ($raw['from_phone'] ?? $raw['phone'] ?? ''));
    }

    /**
     * @return list<array{role: string, text: string}>
     */
    private function priorTurns(InboundMessage $message): array
    {
        return $this->conversation->turns(
            $this->channelName(),
            $message->externalUserId,
            $this->threadKey($message),
        );
    }

    private function rememberTurn(InboundMessage $message, string $role, string $text): void
    {
        $this->conversation->remember(
            $this->channelName(),
            $message->externalUserId,
            $role,
            $text,
            $this->threadKey($message),
        );
    }

    public function handleInbound(InboundMessage $message): ?string
    {
        $voice = $this->voiceNormalizer->normalize($message);
        if (($voice['error'] ?? null) !== null) {
            Log::info('telegram_spike.voice_failed', [
                'from' => $message->externalUserId,
                'error_preview' => mb_substr((string) $voice['error'], 0, 120),
            ]);

            return (string) $voice['error'];
        }
        $message = $voice['message'];
        $wasVoice = ($message->raw['input_modality'] ?? null) === 'voice';
        if ($wasVoice) {
            Log::info('telegram_spike.voice_ready', [
                'from' => $message->externalUserId,
                'chat_type' => $message->raw['chat_type'] ?? null,
                'transcript_preview' => mb_substr($message->text, 0, 240),
                'whisper_language' => $message->raw['whisper_language'] ?? null,
            ]);
        }

        $image = $this->imageNormalizer->normalize($message);
        if (($image['error'] ?? null) !== null) {
            Log::info('telegram_spike.image_failed', [
                'from' => $message->externalUserId,
                'error_preview' => mb_substr((string) $image['error'], 0, 120),
            ]);

            return (string) $image['error'];
        }
        $message = $image['message'];
        $wasImage = ($message->raw['input_modality'] ?? null) === 'image';
        if ($wasImage) {
            Log::info('telegram_spike.image_ready', [
                'from' => $message->externalUserId,
                'chat_type' => $message->raw['chat_type'] ?? null,
                'extract_preview' => mb_substr($message->text, 0, 240),
            ]);
        }

        if ($message->text === '') {
            Log::info('telegram_spike.empty_after_media', [
                'from' => $message->externalUserId,
                'had_media' => is_array($message->raw['media'] ?? null),
            ]);

            return null;
        }

        $adminReply = $this->tryHandleAdminEscalationReply($message);
        if ($adminReply !== null) {
            return $adminReply;
        }

        $draftPublish = $this->tryAdminDraftPublishSwipe($message);
        if ($draftPublish !== null) {
            return $draftPublish;
        }

        $upper = strtoupper($message->text);

        if (str_starts_with($upper, 'JOIN-') || str_starts_with($upper, '/JOIN')) {
            return $this->handleJoin($message);
        }

        if ($this->listenGate->startsWithHelpOrStart($message->text)) {
            return $this->helpText($message);
        }

        if ($this->listenGate->startsWithSlashCommand($message->text, 'share')) {
            return $this->handleShareStub($message);
        }

        if ($this->listenGate->startsWithSlashCommand($message->text, 'features')) {
            return $this->handleAdminKnowledgeDesk($message);
        }

        if ($this->listenGate->startsWithSlashCommand($message->text, 'feature')) {
            return $this->handleFeature($message);
        }

        if ($this->listenGate->startsWithSlashCommand($message->text, 'assets')) {
            return $this->handleMemberAssets($message);
        }

        if ($this->listenGate->startsWithImportOrExport($message->text)) {
            return $this->handleAdminImport($message);
        }

        if ($this->listenGate->startsWithSlashCommand($message->text, 'asset')) {
            return $this->handleAdminAsset($message);
        }

        if ($this->listenGate->startsWithSlashCommand($message->text, 'publish')
            || $this->listenGate->startsWithSlashCommand($message->text, 'unpublish')
            || $this->listenGate->startsWithSlashCommand($message->text, 'archive')
            || $this->listenGate->startsWithSlashCommand($message->text, 'knowledge')
            || $this->listenGate->startsWithSlashCommand($message->text, 'kb')) {
            return $this->handleAdminKnowledgeDesk($message);
        }

        if ($this->listenGate->startsWithSlashCommand($message->text, 'ask')) {
            return $this->handleMemberAsk($message);
        }

        $chatType = strtolower(trim((string) ($message->raw['chat_type'] ?? 'private')));
        $aliases = array_values(array_filter([
            'zak',
            'zak_bot',
            trim((string) config('zak_presence.telegram_handle', ''), " \t\n\r\0\x0B@"),
        ]));

        $isAdmin = $this->commandAccess->isAdmin(
            $this->channelName(),
            $message->externalUserId,
            is_array($message->raw) ? $message->raw : [],
        );

        $adminIndexAck = null;
        if ($isAdmin) {
            $adminIndexAck = $this->tryAdminAutoIndex($message);
        }

        if (! $this->isDirectedAtBot($message, $aliases, $chatType)) {
            return $adminIndexAck;
        }

        $community = $this->resolveLinkedCommunity($message);
        $scope = $community?->description;
        $priorTurns = $this->priorTurns($message);
        $inboundText = $this->textForRouting($message);
        $resolved = $this->conversation->resolveInbound($inboundText, $priorTurns, $scope);
        $resolved = $this->applyModelIntentIfNeeded($resolved, $community, $message, $priorTurns);
        $intent = $resolved['intent'];
        $effectiveQuery = $resolved['query'];

        Log::info('telegram_spike.routed', [
            'from' => $message->externalUserId,
            'voice' => $wasVoice,
            'image' => $wasImage,
            'intent' => $intent,
            'link_mode' => $resolved['link_mode'] ?? 'none',
            'inbound_preview' => mb_substr($inboundText, 0, 160),
            'query_preview' => mb_substr((string) $effectiveQuery, 0, 160),
        ]);

        if ($intent === 'clarify') {
            $reply = $this->modelAssistedReply($inboundText, 'social', $community, $message);
            if ($reply === '' || str_contains(mb_strtolower($reply), 'thanks for joining')) {
                $reply = $this->conversation->clarificationReply();
            }
            $this->rememberTurn(
                $message,
                'user',
                $this->conversation->userTurnTextToRemember($message->text, $effectiveQuery),
            );
            $this->rememberTurn($message, 'assistant', $reply);
            Log::info('telegram_spike.reply', [
                'path' => 'clarify',
                'reply_preview' => mb_substr($reply, 0, 160),
            ]);

            return $reply;
        }

        if ($intent === ChannelConversationService::INTENT_CONVERSATIONAL) {
            $reply = $this->handleConversational($message, $community, $inboundText);
            Log::info('telegram_spike.reply', [
                'path' => 'conversational',
                'reply_preview' => mb_substr((string) $reply, 0, 160),
            ]);

            return $reply;
        }

        if ($intent === ChannelConversationService::INTENT_OUT_OF_SCOPE) {
            $reply = $this->modelAssistedReply(
                $inboundText,
                'out_of_scope',
                $community,
                $message,
            );
            $this->rememberTurn(
                $message,
                'user',
                $this->conversation->userTurnTextToRemember($message->text, $effectiveQuery),
            );
            $this->rememberTurn($message, 'assistant', $reply);
            Log::info('telegram_spike.reply', [
                'path' => 'out_of_scope',
                'reply_preview' => mb_substr($reply, 0, 160),
            ]);

            return $reply;
        }

        if ($intent === ChannelConversationService::INTENT_PERSONAL_HELP) {
            $reply = $this->handlePersonalHelp($message, $community, $inboundText);
            Log::info('telegram_spike.reply', [
                'path' => 'personal_help',
                'reply_preview' => mb_substr((string) $reply, 0, 160),
            ]);

            return $reply;
        }

        $askReply = $this->handleAsk(
            $message,
            $effectiveQuery,
            linkMode: (string) ($resolved['link_mode'] ?? 'none'),
            linkFocus: (string) ($resolved['link_focus'] ?? 'na'),
        );
        Log::info('telegram_spike.reply', [
            'path' => 'ask',
            'link_mode' => $resolved['link_mode'] ?? 'none',
            'reply_preview' => mb_substr((string) ($askReply ?? ''), 0, 160),
            'empty' => $askReply === null || trim((string) $askReply) === '',
        ]);

        return $askReply;
    }

    /**
     * Hybrid cascade: rules for social + hard OOS; model for everything else.
     * Multilingual follow-ups come from classify follow_up=yes (any language).
     *
     * @param  array{intent: string, query: string, link_mode?: string}  $resolved
     * @param  list<array{role: string, text: string}>  $priorTurns
     * @return array{intent: string, query: string, link_mode: string}
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

        $ctx = $this->communityAiContext($community);
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

    /**
     * @return array{name: string, scope: string}
     */
    private function communityAiContext(?Community $community): array
    {
        return $this->conversation->communityModelContext(
            $community?->name,
            $community?->description,
        );
    }

    private function resolveLinkedCommunity(InboundMessage $message): ?Community
    {
        $link = Cache::get($this->linkCacheKey($message->externalUserId));
        $communityId = is_array($link)
            ? (string) ($link['community_id'] ?? '')
            : (string) config('telegram_spike.default_community_id');

        if ($communityId === '') {
            return null;
        }

        return Community::query()->find($communityId);
    }

    private function tryHandleAdminEscalationReply(InboundMessage $message): ?string
    {
        $adminChatId = trim((string) config('telegram_spike.admin_chat_id', ''));
        if ($adminChatId === '' || $message->externalUserId !== $adminChatId) {
            return null;
        }

        $standalone = $this->escalationNotifier->tryAdminCommand(
            $message->text,
            $this->channelName(),
            $message->externalUserId,
        );
        if ($standalone !== null) {
            return (string) ($standalone['reply'] ?? 'Done.');
        }

        $replyTo = trim((string) ($message->raw['reply_to_message_id'] ?? ''));
        if ($replyTo === '') {
            return null;
        }

        $result = $this->escalationNotifier->forwardAdminReply(
            $replyTo,
            $message->text,
            $message->externalUserId,
        );

        return (string) ($result['reply'] ?? 'Done.');
    }

    private function handleMemberAsk(InboundMessage $message): string
    {
        $body = $this->listenGate->slashCommandBody($message->text, 'ask');

        if ($body === '') {
            $reply = "Send your question like this:\n"
                ."/ask When is the next UniPods session?\n\n"
                .'If I already know the answer, I\'ll reply right away. '
                .'Otherwise an admin will see it and can reply from their side.';
            $this->rememberTurn($message, 'user', $message->text);
            $this->rememberTurn($message, 'assistant', $reply);

            return $reply;
        }

        $fromName = trim((string) ($message->raw['from_name'] ?? ''));
        if ($fromName === '' && isset($message->raw['from_username'])) {
            $fromName = '@'.ltrim((string) $message->raw['from_username'], '@');
        }

        // Prefer answering from community knowledge first; escalate only when we cannot.
        $answered = $this->tryAnswerMemberAsk($message, $body);
        if ($answered !== null) {
            $this->rememberTurn($message, 'user', $message->text);
            $this->rememberTurn($message, 'assistant', $answered);

            return $answered;
        }

        $community = $this->resolveLinkedCommunity($message);
        $communityId = $community?->id
            ?? (string) config('telegram_spike.default_community_id', '');

        $result = $this->escalationNotifier->handleMemberAsk(
            channel: $this->channelName(),
            from: $message->externalUserId,
            question: $body,
            communityId: (string) $communityId,
            communityName: $community?->name,
            fromName: $fromName !== '' ? $fromName : null,
            chatType: $this->chatType($message),
            chatId: trim((string) ($message->raw['chat_id'] ?? '')) ?: null,
            messageId: $message->messageId,
        );

        $reply = (string) ($result['reply'] ?? 'Done.');
        $this->rememberTurn($message, 'user', $message->text);
        $this->rememberTurn($message, 'assistant', $reply);

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
            : (string) config('telegram_spike.default_community_id');

        if ($user === null || $communityId === '' || ! $user->belongsToCommunity($communityId)) {
            return null;
        }

        $community = Community::query()->find($communityId);
        if ($community === null) {
            return null;
        }

        // Hard out-of-scope /ask still goes to an admin (member asked on purpose).
        if ($this->conversation->isClearlyOutOfScope($question)) {
            return null;
        }

        $override = $message->raw['target_language'] ?? null;
        $targetLanguage = is_string($override) && trim($override) !== '' && strtolower(trim($override)) !== 'auto'
            ? trim($override)
            : null;

        $priorTurns = $this->priorTurns($message);
        $query = $this->conversation->buildKnowledgeQuery($question, $priorTurns);

        $result = $this->aiClient->askGroundedQuestion(
            query: $query,
            tenantId: $community->tenant_id,
            communityIds: [$communityId],
            targetLanguage: $targetLanguage,
            linkMode: 'none',
        );
        $result = $this->citationRevalidator->revalidate($user, $result);

        if ($this->isTransientAiFailure($result) || trim((string) $result->answer) === '') {
            return null;
        }

        $chatType = (string) ($message->raw['chat_type'] ?? 'private');
        $reply = $this->formatAskReply($result, $chatType, $question, 'none');
        if ($reply === '' || str_starts_with($reply, "I don't have a solid answer for that yet.")) {
            return null;
        }

        return $reply;
    }

    private function handleConversational(
        InboundMessage $message,
        ?Community $community = null,
        ?string $inboundText = null,
    ): string {
        $text = trim((string) ($inboundText ?? $message->text));
        if ($text === '') {
            $text = $message->text;
        }

        if ($this->conversation->isBareBotPing($text)) {
            $reply = $this->conversation->mentionPingReply(
                'plain',
                'telegram',
                $this->chatType($message),
            );
            $this->rememberTurn($message, 'user', $message->text);
            $this->rememberTurn($message, 'assistant', $reply);

            return $reply;
        }

        $reply = $this->modelAssistedReply($text, 'social', $community, $message);
        if ($reply === '') {
            $reply = $this->conversation->mentionPingReply(
                'plain',
                'telegram',
                $this->chatType($message),
            );
        }
        $this->rememberTurn($message, 'user', $message->text);
        $this->rememberTurn($message, 'assistant', $reply);

        return $reply;
    }

    /**
     * Text used for intent routing. Bare @zak on a quote → use the quoted question.
     */
    private function textForRouting(InboundMessage $message): string
    {
        $text = trim($message->text);
        $quoted = trim((string) ($message->raw['quoted_text'] ?? ''));
        $replyToBot = filter_var($message->raw['reply_to_bot'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($quoted !== '' && $this->isBareBotMention($text)) {
            if ($replyToBot) {
                return $text;
            }

            return $quoted;
        }

        if ($replyToBot) {
            return $text;
        }

        return $this->withQuotedContext($message, $text);
    }

    /**
     * When the member replied while quoting another message, fold that quote into the query.
     * Never fold admin escalation/share cards.
     * Never fold our own prior answer when the member is following up.
     */
    private function withQuotedContext(InboundMessage $message, string $query): string
    {
        $query = trim($query);
        if ($this->shouldSkipQuotedFold($message, $query)) {
            return $query;
        }

        $quoted = trim((string) ($message->raw['quoted_text'] ?? ''));
        if ($quoted === '') {
            return $query;
        }

        $lower = mb_strtolower($quoted);
        if (str_contains($lower, 'request id:')
            && (str_contains($lower, 'needs a quick hand')
                || str_contains($lower, 'member shared a note')
                || str_contains($lower, 'member requested a feature'))) {
            return $query;
        }

        // Already the quoted ask (from textForRouting) or already wrapped.
        if ($query === $quoted || str_starts_with($query, 'Regarding this ')
            || str_starts_with($query, 'The member is following up')) {
            return $query;
        }

        $quoted = mb_substr($quoted, 0, 800);
        $who = trim((string) ($message->raw['quoted_from'] ?? ''));
        $label = $who !== '' ? "earlier message from {$who}" : 'earlier message';

        if ($query === '' || $this->isBareBotMention($query)) {
            return "Regarding this {$label}:\n\"{$quoted}\"";
        }

        return "Regarding this {$label}:\n\"{$quoted}\"\n\nCurrent message:\n{$query}";
    }

    private function shouldSkipQuotedFold(InboundMessage $message, string $query): bool
    {
        $replyToBot = filter_var($message->raw['reply_to_bot'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (! $replyToBot) {
            return false;
        }

        return true;
    }

    private function isBareBotMention(string $text): bool
    {
        $stripped = trim(preg_replace(
            '/^(?:hey|hi|hello)?\s*[,:]?\s*@?zak(?:[_\s-]?bot)?\b[,:]?\s*/iu',
            '',
            trim($text),
        ) ?? '');

        return $stripped === '';
    }

    private function handlePersonalHelp(
        InboundMessage $message,
        ?Community $community,
        ?string $inboundText = null,
    ): string {
        $text = trim((string) ($inboundText ?? $message->text));
        $mode = $this->chatType($message) === 'group' ? 'take_private' : 'personal_help';
        $reply = $this->modelAssistedReply($text, $mode, $community, $message);
        $this->rememberTurn($message, 'user', $message->text);
        $this->rememberTurn($message, 'assistant', $reply);

        return $reply;
    }

    private function modelAssistedReply(
        string $text,
        string $mode,
        ?Community $community,
        ?InboundMessage $message = null,
    ): string {
        $chatType = $message !== null ? $this->chatType($message) : 'private';
        if ($mode === 'social' && $this->conversation->isZakCapabilityAsk($text)) {
            return $this->conversation->zakCapabilityReply(
                'plain',
                'telegram',
                $chatType,
                $this->memberPhoneForWeb($message),
            );
        }

        if ($mode === 'social' && $this->conversation->isChannelPresenceAsk($text)) {
            return $this->conversation->channelPresenceReply(
                'plain',
                'telegram',
                $chatType,
                $this->memberPhoneForWeb($message),
            );
        }

        $ctx = $this->communityAiContext($community);
        $override = $message?->raw['target_language'] ?? null;
        $targetLanguage = is_string($override) && trim($override) !== '' && strtolower(trim($override)) !== 'auto'
            ? trim($override)
            : null;
        $reply = $this->aiClient->conversationalReply(
            message: $text,
            mode: $mode,
            communityName: $ctx['name'],
            communityScope: $ctx['scope'],
            targetLanguage: $targetLanguage,
        );

        if ($reply !== '') {
            if (
                $mode === 'out_of_scope'
                && $this->conversation->shouldAppendEnglishAskHint($reply, $text)
            ) {
                return rtrim($reply)."\n\n".$this->conversation->askAdminHint();
            }

            if ($mode === 'take_private') {
                return $this->conversation->withPrivateChatLink($reply, 'plain', 'telegram');
            }

            return $reply;
        }

        if ($mode === 'take_private') {
            return $this->conversation->takePrivateFallbackReply('plain', 'telegram');
        }

        if ($mode === 'out_of_scope') {
            return $this->conversation->outOfScopeReply($community?->name, $community?->description);
        }

        if ($mode === 'personal_help') {
            return $this->conversation->personalHelpFallbackReply();
        }

        return $this->conversation->conversationalReply($text, 'plain', 'telegram', $chatType);
    }

    private function handleJoin(InboundMessage $message): string
    {
        $raw = trim($message->text);
        if (str_starts_with(strtoupper($raw), '/JOIN')) {
            $raw = trim(substr($raw, 5));
        }
        if (str_starts_with(strtoupper($raw), 'JOIN-')) {
            $raw = trim(substr($raw, 5));
        }

        $token = $raw;
        // Only accept admin-minted join codes from cache (no raw community ULID bypass).
        $communityId = Cache::pull('telegram_spike_join:'.strtolower($token));

        if ($communityId === null || ! Community::query()->whereKey($communityId)->exists()) {
            return "Sorry, that join code didn't work or may have expired. "
                ."Please ask your admin for a fresh one, then try /join again.";
        }

        $user = $this->resolveUser();
        if ($user === null) {
            return "Sorry, I can't link you just yet. Please ask your admin to check the bot setup.";
        }

        Cache::forever($this->linkCacheKey($message->externalUserId), [
            'user_id' => $user->id,
            'community_id' => $communityId,
        ]);

        $reply = "Welcome! You're linked now. Ask me anything about this community whenever you're ready.";
        $this->rememberTurn($message, 'user', $message->text);
        $this->rememberTurn($message, 'assistant', $reply);

        return $reply;
    }

    private function handleAsk(
        InboundMessage $message,
        ?string $effectiveQuery = null,
        string $linkMode = 'none',
        string $linkFocus = 'na',
    ): string {
        $link = Cache::get($this->linkCacheKey($message->externalUserId));
        $user = $this->resolveUser();
        $communityId = is_array($link)
            ? (string) ($link['community_id'] ?? '')
            : (string) config('telegram_spike.default_community_id');

        if ($user === null) {
            return "Sorry, I can't look that up yet. Please ask your admin to finish setting me up.";
        }

        if ($communityId === '') {
            return "You're not linked to a community yet. Please ask your admin for a code, then send /join YOURCODE.";
        }

        if (! $user->belongsToCommunity($communityId)) {
            return "It looks like you're not a member of that community in "
                .$this->conversation->botDisplayName().' yet. An admin can add you.';
        }

        $community = Community::query()->find($communityId);
        if ($community === null) {
            return "Sorry, I couldn't find that community. Could you double-check with your admin?";
        }

        $override = $message->raw['target_language'] ?? null;
        $targetLanguage = is_string($override) && trim($override) !== '' && strtolower(trim($override)) !== 'auto'
            ? trim($override)
            : null;

        $question = trim((string) ($effectiveQuery ?? $message->text));
        if (! $this->shouldSkipQuotedFold($message, $question)) {
            $question = $this->withQuotedContext($message, $question);
        }
        if ($question === '') {
            $question = trim($message->text);
        }

        $allowedLink = ['none', 'recordings', 'meetings', 'assets'];
        if (! in_array($linkMode, $allowedLink, true)) {
            $linkMode = 'none';
        }
        $allowedFocus = ['one', 'many', 'na'];
        if (! in_array($linkFocus, $allowedFocus, true)) {
            $linkFocus = 'na';
        }
        if ($linkMode === 'none') {
            $linkFocus = 'na';
        } elseif ($linkFocus === 'na') {
            $linkFocus = 'many';
        }

        $priorTurns = $this->priorTurns($message);
        $query = $this->conversation->buildKnowledgeQuery($question, $priorTurns);

        $result = $this->aiClient->askGroundedQuestion(
            query: $query,
            tenantId: $community->tenant_id,
            communityIds: [$communityId],
            targetLanguage: $targetLanguage,
            linkMode: $linkMode,
            linkFocus: $linkFocus,
        );

        $result = $this->citationRevalidator->revalidate($user, $result);

        $chatType = (string) ($message->raw['chat_type'] ?? 'private');
        $reply = $this->formatAskReply($result, $chatType, $question, $linkMode, $linkFocus);

        $softHandoff = str_starts_with($reply, "I don't have a solid answer for that yet.");
        $shouldEscalate = ($result->answer === '' || $softHandoff)
            && ! $this->isTransientAiFailure($result)
            && $this->conversation->shouldEscalateKnowledgeGap($question, $community->description);

        if ($softHandoff && ! $shouldEscalate) {
            $mode = ($this->conversation->isPurelySocial($question)
                || $this->conversation->isBotDirectedChat($question))
                ? 'social'
                : 'out_of_scope';
            $reply = $this->modelAssistedReply(
                $question,
                $mode,
                $community,
                $message,
            );
        }

        if ($shouldEscalate) {
            $fromName = trim((string) ($message->raw['from_name'] ?? ''));
            if ($fromName === '' && isset($message->raw['from_username'])) {
                $fromName = '@'.ltrim((string) $message->raw['from_username'], '@');
            }

            $this->escalationNotifier->escalate([
                'question' => $question,
                'from' => $message->externalUserId,
                'from_name' => $fromName !== '' ? $fromName : null,
                'chat_type' => $this->chatType($message),
                'chat_id' => trim((string) ($message->raw['chat_id'] ?? '')) ?: null,
                'message_id' => $message->messageId,
                'community_id' => $communityId,
                'community_name' => $community->name,
                'reason' => (string) ($result->escalationReason ?? 'insufficient_evidence'),
                'channel' => $this->channelName(),
            ]);
        }

        $this->rememberTurn(
            $message,
            'user',
            $this->conversation->userTurnTextToRemember($message->text, $question),
        );
        $this->rememberTurn($message, 'assistant', $reply);

        return $reply;
    }

    private function isTransientAiFailure(GroundedAnswerDTO $result): bool
    {
        $reason = strtolower((string) ($result->escalationReason ?? ''));

        return str_contains($reason, 'ai service')
            || str_contains($reason, 'unreachable')
            || str_contains($reason, 'timed out');
    }

    private function formatAskReply(
        GroundedAnswerDTO $result,
        string $chatType = 'private',
        string $originalQuestion = '',
        string $linkMode = 'none',
        string $linkFocus = 'na',
    ): string {
        $isPrivate = in_array($chatType, ['private', ''], true);

        if ($result->answer === '') {
            if ($this->isTransientAiFailure($result)) {
                return $this->conversation->transientDeferralReply();
            }

            if ($isPrivate) {
                return "I don't have a solid answer for that yet.\n\n"
                    ."I've passed it along, and I'll follow up once I have one. "
                    ."No need to keep checking or asking again.";
            }

            return "I don't have a solid answer for that yet.\n\n"
                ."I've passed it along, and we'll follow up once we have one. "
                ."No need to keep checking or asking again.";
        }

        $answer = $this->utf8Safe($result->answer);
        $answer = preg_replace('/\s*\[E\d+(?:\s*\([^)]*\))?\]/u', '', $answer) ?? $answer;
        $answer = $this->normalizeOpenableLinks($answer);
        $answer = $this->ensureReadableStructure($answer);
        $answer = $this->ensureOpenableUrlsPresent(
            $answer,
            $result,
            $originalQuestion !== '' ? $originalQuestion : $result->query,
            $linkMode,
            $linkFocus,
        );
        $answer = $this->finalizeOpenableUrlsInAnswer($answer, $result);
        $answer = $this->ensureListHasIntro(
            $answer,
            $originalQuestion !== '' ? $originalQuestion : $result->query,
            $linkMode,
        );

        // Hollow "Recording Links" style replies with nothing openable → same soft handoff.
        if ($answer === '') {
            if ($isPrivate) {
                return "I don't have a solid answer for that yet.\n\n"
                    ."I've passed it along, and I'll follow up once I have one. "
                    ."No need to keep checking or asking again.";
            }

            return "I don't have a solid answer for that yet.\n\n"
                ."I've passed it along, and we'll follow up once we have one. "
                ."No need to keep checking or asking again.";
        }

        $answer = $this->conversation->stripInternalEvidenceTags($answer);

        return $this->applyTelegramFormatting($answer);
    }

    /**
     * Telegram free clients show raw *asterisks* unless parse_mode is used.
     * Default is plain (strip markdown emphasis). Set TELEGRAM_SPIKE_FORMATTING=html
     * to keep emphasis markers for the bot to render with parse_mode=HTML.
     */
    private function applyTelegramFormatting(string $answer): string
    {
        // Product copy must not use em dashes.
        $answer = str_replace(["\u{2014}", "\u{2013}"], ['-', '-'], $answer);

        $mode = strtolower(trim((string) config('telegram_spike.formatting', 'plain')));

        if ($mode === 'html') {
            return $answer;
        }

        return $this->stripMarkdownEmphasis($answer);
    }

    private function stripMarkdownEmphasis(string $text): string
    {
        // **bold** then *italic* / _italic_
        $text = preg_replace('/\*\*(.+?)\*\*/us', '$1', $text) ?? $text;
        $text = preg_replace('/\*(.+?)\*/us', '$1', $text) ?? $text;
        $text = preg_replace('/_(.+?)_/us', '$1', $text) ?? $text;

        return $text;
    }

    /**
     * Prefer plain openable URLs. Drop markdown links that point at internal
     * knowledge URIs (whatsapp://export/..., community://...), which members cannot open.
     */
    private function normalizeOpenableLinks(string $answer): string
    {
        $normalized = preg_replace_callback(
            '/\[([^\]]*)\]\(([^)]+)\)/u',
            static function (array $matches): string {
                $label = trim($matches[1]);
                $url = trim($matches[2]);
                $lower = strtolower($url);

                if (str_starts_with($lower, 'http://') || str_starts_with($lower, 'https://')) {
                    return $label !== '' && strcasecmp($label, $url) !== 0
                        ? "{$label}\n{$url}"
                        : $url;
                }

                // Internal / non-openable schemes: drop entirely (label alone is useless).
                return '';
            },
            $answer
        ) ?? $answer;

        return trim($normalized);
    }

    private function ensureReadableStructure(string $answer): string
    {
        // Keep line breaks; only collapse repeated spaces/tabs within a line.
        $lines = preg_split("/\r\n|\n|\r/u", $answer) ?: [$answer];
        $clean = [];
        foreach ($lines as $line) {
            $line = trim(preg_replace('/[ \t]+/u', ' ', $line) ?? $line);
            $clean[] = $line;
        }

        $text = implode("\n", $clean);
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        // Soft-wrap jammed numbered lists ("1. a 2. b"), but only 1-2 digit
        // markers so years like "2026." stay on the same line as the date.
        $text = preg_replace('/\s+([1-9]\d?)\.\s+/u', "\n$1. ", $text) ?? $text;
        // Soft-wrap "- item" / "• item" when jammed after other text.
        $text = preg_replace('/\s+([•\-])\s+(?=\S)/u', "\n$1 ", $text) ?? $text;

        $spaced = [];
        foreach (preg_split("/\n/u", $text) ?: [$text] as $line) {
            $line = trim($line);
            $isList = preg_match('/^(?:[1-9]\d?\.|[•\-])\s+\S/u', $line) === 1;
            $prev = $spaced[array_key_last($spaced)] ?? null;
            $prevIsList = is_string($prev) && preg_match('/^(?:[1-9]\d?\.|[•\-])\s+\S/u', $prev) === 1;

            // Blank line after a heading / lead sentence before the first list item.
            if ($isList && is_string($prev) && $prev !== '' && ! $prevIsList) {
                $spaced[] = '';
            }

            // Blank line after the list before a closing prose sentence.
            if (! $isList && $line !== '' && $prevIsList) {
                $spaced[] = '';
            }

            $spaced[] = $line;
        }

        $text = implode("\n", $spaced);
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function ensureOpenableUrlsPresent(
        string $answer,
        GroundedAnswerDTO $result,
        string $originalQuestion = '',
        string $linkMode = 'none',
        string $linkFocus = 'na',
    ): string {
        $question = $originalQuestion !== '' ? $originalQuestion : $this->currentQuestionOnly($result->query);
        $urlsInAnswer = $this->extractHttpUrls($answer);
        $evidenceText = '';
        $urlsFromEvidence = [];
        foreach ($result->citations as $citation) {
            $chunk = $citation->exactQuote."\n".$citation->contextSnippet;
            $evidenceText .= $chunk."\n";
            foreach ($this->extractHttpUrls($chunk) as $url) {
                $urlsFromEvidence[$url] = true;
            }
        }
        $evidenceUrls = array_keys($urlsFromEvidence);

        // Prefer model link_mode (any language). English regex is offline fallback only.
        $mode = $this->resolveUrlListMode($question, $linkMode);
        $filterMode = $mode === 'none' ? 'default' : $mode;
        $wantsMeetingLinks = $mode === 'meetings';
        $wantsRecordings = $mode === 'recordings';
        $wantsGenericLinks = $mode === 'assets';
        $hollow = $this->looksLikeHollowLinkAnswer($answer);
        $wantsAnyLinkList = $wantsMeetingLinks || $wantsRecordings || $wantsGenericLinks;
        // Short factual answers ("Saturday 9am.") are valid. Only treat tiny
        // replies as empty when the member asked for links/recordings.
        $answerLooksEmpty = $urlsInAnswer === [] && (
            $answer === ''
            || $hollow
            || preg_match('/^(recording links?|links?|here\.?|see (here|below))\.?$/iu', $answer) === 1
            || ($wantsAnyLinkList && mb_strlen($answer) < 40)
        );

        $labels = $this->preferredUrlLabels($answer."\n".$evidenceText);
        $answerModeUrls = $this->uniqueOpenableUrls($urlsInAnswer, mode: $filterMode);
        $evidenceModeUrls = $this->uniqueOpenableUrls($evidenceUrls, mode: $filterMode);

        // Trust a solid AI link list. Never re-merge the citation corpus on top —
        // that turned singular/multi asks into 10–15 generic "Google Drive file" dumps.
        if ($wantsAnyLinkList && ! $hollow && $answerModeUrls !== []) {
            $fixed = $answer;
            if ($wantsRecordings) {
                $fixed = $this->stripNonRecordingUrlsFromAnswer($fixed);
            } elseif ($wantsMeetingLinks) {
                $fixed = $this->stripNonMeetingUrlsFromAnswer($fixed);
            }
            $fixed = $this->expandTruncatedUrlsFromEvidence($fixed, $evidenceModeUrls);

            return $this->ensureReadableStructure(
                $this->replaceBrokenMemberUrls($fixed, $evidenceModeUrls)
            );
        }

        // Hollow / wrong-class AI draft: light fill from evidence only (capped).
        if ($wantsAnyLinkList && $evidenceModeUrls !== []) {
            $focus = strtolower(trim($linkFocus));
            if (! in_array($focus, ['one', 'many', 'na'], true)) {
                $focus = 'na';
            }
            $candidateUrls = $evidenceModeUrls;
            if ($focus === 'one') {
                $candidateUrls = array_slice($candidateUrls, 0, 1);
            } elseif ($mode === 'assets') {
                $candidateUrls = array_slice($candidateUrls, 0, 5);
            } else {
                $candidateUrls = array_slice($candidateUrls, 0, 6);
            }

            $list = $this->linkListIntro($question, $mode)."\n\n".$this->formatLabeledLinkList($candidateUrls, $labels);
            if ($this->hasAdditionalNonLinkQuestion($question)) {
                $prose = $this->proseWithoutHttpUrls($answer);
                if ($prose !== '' && ! $hollow && mb_strlen($prose) >= 24 && ! $this->looksLikeHollowLinkAnswer($prose)) {
                    return $this->ensureReadableStructure($prose)."\n\n".$list;
                }
            }

            return $list;
        }

        // Typed recording/meeting asks: never pass through LinkedIn / random URL dumps.
        if (($wantsRecordings || $wantsMeetingLinks) && $answerModeUrls === [] && $evidenceModeUrls === []) {
            return '';
        }

        if ($urlsInAnswer !== [] && ! $hollow) {
            return $this->ensureReadableStructure($answer);
        }

        if ($urlsInAnswer === [] && ($hollow || $answerLooksEmpty)) {
            return '';
        }

        return $this->ensureReadableStructure($answer);
    }

    /**
     * Replace cut-off http(s) twins in the answer with longer evidence URLs (same dedupe key).
     *
     * @param  list<string>  $evidenceUrls
     */
    private function expandTruncatedUrlsFromEvidence(string $answer, array $evidenceUrls): string
    {
        if ($answer === '' || $evidenceUrls === []) {
            return $answer;
        }

        $byKey = [];
        foreach ($evidenceUrls as $url) {
            $key = $this->urlDedupeKey($url);
            if (! isset($byKey[$key]) || strlen($url) > strlen($byKey[$key])) {
                $byKey[$key] = $url;
            }
        }

        foreach ($this->extractHttpUrls($answer) as $url) {
            $key = $this->urlDedupeKey($url);
            $full = $byKey[$key] ?? null;
            if ($full === null || $full === $url) {
                continue;
            }
            if (strlen($full) > strlen($url) || $this->isBrokenMemberUrl($url)) {
                $answer = str_replace($url, $full, $answer);
            }
        }

        return $this->replaceBrokenMemberUrls($answer, $evidenceUrls);
    }

    /**
     * Last pass: swap placeholder / ellipsis Drive URLs for real ones from citations.
     */
    private function finalizeOpenableUrlsInAnswer(string $answer, GroundedAnswerDTO $result): string
    {
        if ($answer === '') {
            return $answer;
        }

        $evidenceUrls = [];
        foreach ($result->citations as $citation) {
            $chunk = $citation->exactQuote."\n".$citation->contextSnippet;
            foreach ($this->extractHttpUrls($chunk) as $url) {
                $evidenceUrls[] = $url;
            }
        }

        $openable = $this->uniqueOpenableUrls($evidenceUrls, mode: 'assets');
        if ($openable === []) {
            $openable = $this->uniqueOpenableUrls($evidenceUrls, mode: 'default');
        }

        $answer = $this->expandTruncatedUrlsFromEvidence($answer, $openable);

        return $this->replaceBrokenMemberUrls($answer, $openable);
    }

    /**
     * Placeholder or truncated URLs must never reach members (e.g. folders/…, YOUR_FOLDER_ID).
     */
    private function isBrokenMemberUrl(string $url): bool
    {
        $url = rtrim(trim($url), ".,);]}>\"'");
        if ($url === '' || mb_strlen($url) < 16) {
            return true;
        }
        if (preg_match('/[…]/u', $url) === 1) {
            return true;
        }
        if (preg_match('/YOUR_[A-Z0-9_]+/i', $url) === 1) {
            return true;
        }
        if (preg_match('/\.{2,}(?:\/|$|\?|#)/u', $url) === 1) {
            return true;
        }
        if (preg_match('#drive\.google\.com/drive/folders/([^/?]+)#i', $url, $matches) === 1) {
            return ! $this->usableDriveResourceId($matches[1]);
        }
        if (preg_match('#drive\.google\.com/file/d/([^/?]+)#i', $url, $matches) === 1) {
            return ! $this->usableDriveResourceId($matches[1]);
        }
        if (preg_match('#docs\.google\.com/(?:document|spreadsheets|presentation)/d/([^/?]+)#i', $url, $matches) === 1) {
            return ! $this->usableDriveResourceId($matches[1]);
        }

        return false;
    }

    private function usableDriveResourceId(string $id): bool
    {
        $id = rawurldecode(trim($id));
        if ($id === '' || preg_match('/[…]/u', $id) === 1 || preg_match('/\.{2,}/', $id) === 1) {
            return false;
        }
        if (preg_match('/^(your_[a-z0-9_]+|xxx+|placeholder|<[^>]+>)$/i', $id) === 1) {
            return false;
        }

        return preg_match('/^[a-zA-Z0-9_-]{5,}$/', $id) === 1;
    }

    /**
     * @param  list<string>  $evidenceUrls
     */
    private function replaceBrokenMemberUrls(string $answer, array $evidenceUrls): string
    {
        if ($answer === '') {
            return $answer;
        }

        $good = [];
        foreach ($evidenceUrls as $url) {
            if (! $this->isBrokenMemberUrl($url)) {
                $good[$url] = true;
            }
        }
        $goodList = array_keys($good);
        if ($goodList === []) {
            return $answer;
        }

        foreach ($this->extractHttpUrls($answer) as $bad) {
            if (! $this->isBrokenMemberUrl($bad)) {
                continue;
            }

            $replacement = $this->pickReplacementUrl($bad, $goodList);
            if ($replacement !== null) {
                $answer = str_replace($bad, $replacement, $answer);

                continue;
            }

            $lines = preg_split("/\r\n|\n|\r/u", $answer) ?: [$answer];
            $filtered = [];
            foreach ($lines as $line) {
                if (str_contains($line, $bad)) {
                    continue;
                }
                $filtered[] = $line;
            }
            $answer = trim(implode("\n", $filtered));
        }

        return $answer;
    }

    /**
     * @param  list<string>  $goodList
     */
    private function pickReplacementUrl(string $broken, array $goodList): ?string
    {
        $brokenLower = strtolower($broken);

        if (str_contains($brokenLower, 'drive.google.com/drive/folders')) {
            foreach ($goodList as $candidate) {
                if (preg_match('#drive\.google\.com/drive/folders/[^/?]+#i', $candidate) === 1) {
                    return $candidate;
                }
            }
        }

        if (str_contains($brokenLower, 'drive.google.com/file/d')) {
            foreach ($goodList as $candidate) {
                if (preg_match('#drive\.google\.com/file/d/[^/?]+#i', $candidate) === 1) {
                    return $candidate;
                }
            }
        }

        if (str_contains($brokenLower, 'drive.google.com') || str_contains($brokenLower, 'docs.google.com')) {
            foreach ($goodList as $candidate) {
                $lower = strtolower($candidate);
                if (str_contains($lower, 'drive.google.com') || str_contains($lower, 'docs.google.com')) {
                    return $candidate;
                }
            }
        }

        return count($goodList) === 1 ? $goodList[0] : null;
    }

    /**
     * @return 'none'|'recordings'|'meetings'|'assets'
     */
    private function resolveUrlListMode(string $question, string $linkMode): string
    {
        $allowed = ['none', 'recordings', 'meetings', 'assets'];
        if (in_array($linkMode, $allowed, true) && $linkMode !== 'none') {
            return $linkMode;
        }

        // Offline English fallback when model classify did not run / failed.
        if ($this->looksLikeMeetingLinkRequest($question)) {
            return 'meetings';
        }
        if ($this->looksLikeRecordingRequest($question)) {
            return 'recordings';
        }
        if ($this->looksLikeLinkRequest($question)) {
            return 'assets';
        }

        return 'none';
    }

    /**
     * @param  list<string>  $urls
     */
    private function answerLacksLinkTitles(string $answer, array $urls): bool
    {
        if ($urls === []) {
            return true;
        }

        $lines = preg_split("/\r\n|\n|\r/u", $answer) ?: [$answer];
        $titled = 0;

        for ($i = 0, $n = count($lines); $i < $n; $i++) {
            $lineUrls = $this->extractHttpUrls($lines[$i]);
            if ($lineUrls === []) {
                continue;
            }
            $prev = $i > 0 ? trim($lines[$i - 1]) : '';
            $label = preg_replace('/^(?:\d+\.|[•\-])\s*/u', '', $prev) ?? $prev;
            $label = $this->normalizeLinkLabel($label);
            if ($label !== '') {
                $titled++;
            }
        }

        return $titled < count($urls);
    }

    private function currentQuestionOnly(string $query): string
    {
        if (preg_match('/Current question:\s*(.+)\s*$/is', $query, $matches) === 1) {
            return trim($matches[1]);
        }

        return $query;
    }

    /**
     * One stable title per URL: prefer the most specific harvested label.
     *
     * @return array<string, string>
     */
    private function preferredUrlLabels(string $text): array
    {
        $harvested = $this->harvestUrlLabels($text);
        $best = [];

        foreach ($harvested as $url => $label) {
            $label = $this->normalizeLinkLabel($label);
            if ($label === '') {
                continue;
            }
            $key = $this->urlDedupeKey($url);
            if (! isset($best[$key]) || $this->labelSpecificity($label) > $this->labelSpecificity($best[$key])) {
                $best[$key] = $label;
            }
        }

        return $best;
    }

    private function normalizeLinkLabel(string $label): string
    {
        $label = trim($label);
        $label = preg_replace('/^\d+\.\s*/u', '', $label) ?? $label;
        $label = preg_replace('/\s+/u', ' ', $label) ?? $label;
        $label = preg_replace('/\s*via this link\b.*$/iu', '', $label) ?? $label;
        $label = preg_replace('/\s*[-–—|]\s*join\s*$/iu', '', $label) ?? $label;
        // Drop trailing filler like "is available" / "link" / "Join".
        $label = preg_replace(
            '/\b(is available|available here|see here|click here|link|recording|video|here|see|watch|available|join)\b\.?$/iu',
            '',
            $label
        ) ?? $label;
        $label = trim($label, " \t:-–—|.");

        if ($label === '' || mb_strlen($label) < 3) {
            return '';
        }

        // WhatsApp export timestamps / bare speaker tags are not titles.
        if (preg_match('/^\[?\d{1,2}\/\d{1,2}\/\d{2,4}/u', $label) === 1) {
            return '';
        }
        if (preg_match('/^~\s*\S+$/u', $label) === 1) {
            return '';
        }

        if (preg_match(
            '/^(session recording|recording|video|link|youtube recording|teams recording|join|click here|microsoft teams)$/iu',
            $label
        ) === 1) {
            return '';
        }

        // Prefer a clean head title, never chop mid-word from the tail.
        if (mb_strlen($label) > 70) {
            $head = mb_substr($label, 0, 70);
            $cut = mb_strrpos($head, ' ');
            $label = $cut !== false ? trim(mb_substr($head, 0, $cut)) : trim($head);
        }

        return $label;
    }

    private function labelSpecificity(string $label): int
    {
        // Prefer concrete session titles over short/generic or truncated ones.
        $score = mb_strlen($label);
        if (preg_match('/\b(module|welcome|wadhwani|coaching|q&a|session|class)\b/iu', $label) === 1) {
            $score += 50;
        }
        if (preg_match('/^[a-z]\s/u', $label) === 1) {
            $score -= 100;
        }
        if (preg_match('/\bjoin\b/iu', $label) === 1) {
            $score -= 40;
        }

        return $score;
    }

    private function looksLikeHollowLinkAnswer(string $answer): bool
    {
        if ($answer === '' || $this->extractHttpUrls($answer) !== []) {
            return false;
        }

        return (bool) preg_match(
            '/\b(recording links?|find (all )?(the )?(available )?recordings?|links? (here|below)|see (here|below))\b/iu',
            $answer
        );
    }

    private function looksLikeRecordingRequest(string $query): bool
    {
        $q = mb_strtolower($query);

        // English-only offline fallback. Multilingual asks use model link_mode.
        return (bool) preg_match('/\b(recording|recordings|replay|recap|video|videos|youtube|youtu)\b/u', $q);
    }

    private function looksLikeMeetingLinkRequest(string $query): bool
    {
        $q = mb_strtolower($query);

        return (bool) preg_match(
            '/\b((?:meeting|meet|join|call)\s+links?|links?\s+(?:for|to)\s+(?:the\s+)?(?:meeting|meet|call|session)|join\s+(?:the\s+)?(?:meeting|call|session))\b/u',
            $q
        );
    }

    /**
     * True when the member also asked something beyond "send links"
     * (e.g. "also any meeting today?").
     */
    private function hasAdditionalNonLinkQuestion(string $query): bool
    {
        $q = mb_strtolower($query);
        if (preg_match('/\balso\b.+/u', $q) === 1) {
            return true;
        }
        if (substr_count($query, '?') >= 2) {
            return true;
        }

        $hasLinkAsk = $this->looksLikeMeetingLinkRequest($q)
            || $this->looksLikeRecordingRequest($q)
            || $this->looksLikeLinkRequest($q);
        $hasScheduleAsk = preg_match(
            '/\b(today|tomorrow|this week|schedule|when|any meeting|meetings?\s+today)\b/u',
            $q
        ) === 1;

        return $hasLinkAsk && $hasScheduleAsk;
    }

    /**
     * @return list<string>
     */
    private function proseWithoutHttpUrls(string $answer): string
    {
        $lines = preg_split("/\r\n|\n|\r/u", $answer) ?: [$answer];
        $kept = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                if ($kept !== [] && end($kept) !== '') {
                    $kept[] = '';
                }

                continue;
            }
            if ($this->extractHttpUrls($trimmed) !== []) {
                continue;
            }
            // Drop orphan list markers left above removed URLs.
            if (preg_match('/^(?:\d+\.|[•\-])\s*$/u', $trimmed) === 1) {
                continue;
            }
            if (preg_match('/^(?:\d+\.|[•\-])\s+\S/u', $trimmed) === 1
                && preg_match('/https?:\/\//iu', $trimmed) !== 1) {
                // Keep titled list items that are prose-only.
                $kept[] = $trimmed;

                continue;
            }
            $kept[] = $trimmed;
        }

        $text = trim(preg_replace("/\n{3,}/u", "\n\n", implode("\n", $kept)) ?? '');
        // Drop a lone "Here are the links:" style lead if nothing followed.
        if (preg_match('/^(here are the .+|here they are):?$/iu', $text) === 1) {
            return '';
        }

        return $text;
    }

    private function isMeetingJoinUrl(string $url): bool
    {
        $lower = strtolower($url);

        return str_contains($lower, 'meetup-join')
            || str_contains($lower, 'light-meetings/launch')
            || str_contains($lower, '/calendar/')
            || str_contains($lower, 'outlook.office')
            || str_contains($lower, 'teams.microsoft.com/meet/')
            || str_contains($lower, 'teams.live.com/meet')
            || str_contains($lower, 'zoom.us/j/')
            || str_contains($lower, 'meet.google.com/')
            || (bool) preg_match('#teams\.microsoft\.com/.*/meet(?:/|\?|$)#i', $lower);
    }

    private function isLikelyCommunityAssetUrl(string $url): bool
    {
        if ($this->isMeetingJoinUrl($url) || $this->isLikelyRecordingUrl($url)) {
            return true;
        }

        $lower = strtolower($url);
        if (str_contains($lower, 'docs.google.com')
            || str_contains($lower, 'forms.gle')
            || str_contains($lower, 'forms.office.com')
            || str_contains($lower, 'sharepoint.com')
            || str_contains($lower, 'notion.so')
            || str_contains($lower, 'notion.site')) {
            return true;
        }

        // Social profiles, personal sites, and group invites are not "meeting links".
        return false;
    }

    private function isLikelyRecordingUrl(string $url): bool
    {
        if ($this->isMeetingJoinUrl($url)) {
            return false;
        }

        $lower = strtolower($url);

        // Prefer known replay / recording hosts over live meeting invites.
        if (str_contains($lower, 'youtu.be/') || str_contains($lower, 'youtube.com/')) {
            return true;
        }
        if (str_contains($lower, 'meetingrecap')) {
            return true;
        }
        if (str_contains($lower, 'stream.microsoft.com')) {
            return true;
        }
        if (str_contains($lower, 'drive.google.com') && str_contains($lower, '/file/')) {
            return true;
        }
        // Sheets / Docs / Forms are not session recordings.
        if (str_contains($lower, 'docs.google.com')) {
            return false;
        }
        if (str_contains($lower, 'sharepoint.com')
            && (str_contains($lower, '.mp4') || str_contains($lower, 'recording'))) {
            return true;
        }
        if (str_contains($lower, 'vimeo.com')) {
            return true;
        }
        if (str_contains($lower, 'facebook.com') && str_contains($lower, 'video')) {
            return true;
        }

        return false;
    }

    /**
     * Stable identity for the same recording across trivial URL variants.
     */
    private function urlDedupeKey(string $url): string
    {
        $url = rtrim($url, ".,);]}>\"'");
        $lower = strtolower($url);

        if (preg_match('#drive\.google\.com/file/d/([^/]+)#i', $url, $matches) === 1) {
            return 'gdrive:'.strtolower($matches[1]);
        }
        if (preg_match('#drive\.google\.com/drive/folders/([^/?]+)#i', $url, $matches) === 1) {
            return 'gdrive-folder:'.strtolower($matches[1]);
        }
        if (preg_match('#youtu\.be/([\w-]+)#i', $url, $matches) === 1) {
            return 'yt:'.strtolower($matches[1]);
        }
        if (str_contains($lower, 'youtube.') && preg_match('#[?&]v=([\w-]+)#i', $url, $matches) === 1) {
            return 'yt:'.strtolower($matches[1]);
        }
        if (str_contains($lower, 'meetingrecap') && preg_match('/driveItemId=([^&]+)/i', $url, $matches) === 1) {
            return 'teams-recap:'.strtolower(urldecode($matches[1]));
        }

        return strtolower(explode('?', $url, 2)[0]);
    }

    /**
     * When two stored URLs are the same resource, prefer the cleaner one
     * (e.g. drop the ?usp=sharing twin). Never invent or rewrite a URL.
     */
    private function preferCleanerStoredUrl(string $current, string $candidate): string
    {
        $score = static function (string $url): int {
            $query = parse_url($url, PHP_URL_QUERY);
            if (! is_string($query) || $query === '') {
                return 0;
            }
            $penalty = 1;
            if (stripos($query, 'usp=') !== false) {
                $penalty += 2;
            }

            return $penalty;
        };

        return $score($candidate) < $score($current) ? $candidate : $current;
    }

    private function isSocialOrProfileNoiseUrl(string $url): bool
    {
        $lower = strtolower($url);

        return str_contains($lower, 'linkedin.com/')
            || str_contains($lower, 'github.com/')
            || str_contains($lower, 'facebook.com/')
            || str_contains($lower, 'instagram.com/')
            || str_contains($lower, 'twitter.com/')
            || str_contains($lower, 'x.com/')
            || str_contains($lower, 'wa.me/')
            || str_contains($lower, 'chat.whatsapp.com/');
    }

    /**
     * @param  list<string>  $urls
     * @param  'default'|'recordings'|'meetings'|'assets'  $mode
     * @return list<string>
     */
    private function uniqueOpenableUrls(array $urls, string $mode = 'default'): array
    {
        $byKey = [];
        foreach ($urls as $url) {
            $url = rtrim($url, ".,);]}>\"'");
            if ($url === '' || $this->isBrokenMemberUrl($url)) {
                continue;
            }
            if ($mode === 'meetings') {
                if (! $this->isMeetingJoinUrl($url)) {
                    continue;
                }
            } elseif ($mode === 'recordings') {
                if ($this->isMeetingJoinUrl($url) || ! $this->isLikelyRecordingUrl($url)) {
                    continue;
                }
            } elseif ($mode === 'assets') {
                if ($this->isSocialOrProfileNoiseUrl($url)) {
                    continue;
                }
            } elseif ($this->isMeetingJoinUrl($url)) {
                continue;
            }
            $key = $this->urlDedupeKey($url);
            if (! isset($byKey[$key])) {
                $byKey[$key] = $url;
            } else {
                $byKey[$key] = $this->preferCleanerStoredUrl($byKey[$key], $url);
            }
        }

        return array_values($byKey);
    }

    private function stripNonRecordingUrlsFromAnswer(string $answer): string
    {
        $lines = preg_split("/\r\n|\n|\r/u", $answer) ?: [$answer];
        $kept = [];
        $pendingLabel = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            $urls = $this->extractHttpUrls($trimmed);

            if ($urls !== []) {
                $url = $urls[0];
                if (! $this->isLikelyRecordingUrl($url)) {
                    $pendingLabel = null;

                    continue;
                }
                if (is_string($pendingLabel) && $pendingLabel !== '') {
                    $kept[] = $pendingLabel;
                    $pendingLabel = null;
                }
                $kept[] = $trimmed;

                continue;
            }

            if (preg_match('/^(?:\d+\.|[•\-])\s+\S/u', $trimmed) === 1) {
                $pendingLabel = $trimmed;

                continue;
            }

            if ($pendingLabel !== null) {
                $kept[] = $pendingLabel;
                $pendingLabel = null;
            }
            $kept[] = $line;
        }

        if ($pendingLabel !== null) {
            $kept[] = $pendingLabel;
        }

        $text = implode("\n", $kept);
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function stripNonMeetingUrlsFromAnswer(string $answer): string
    {
        $lines = preg_split("/\r\n|\n|\r/u", $answer) ?: [$answer];
        $kept = [];
        $pendingLabel = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            $urls = $this->extractHttpUrls($trimmed);

            if ($urls !== []) {
                $url = $urls[0];
                if (! $this->isMeetingJoinUrl($url)) {
                    $pendingLabel = null;

                    continue;
                }
                if (is_string($pendingLabel) && $pendingLabel !== '') {
                    $kept[] = $pendingLabel;
                    $pendingLabel = null;
                }
                $kept[] = $trimmed;

                continue;
            }

            if (preg_match('/^(?:\d+\.|[•\-])\s+\S/u', $trimmed) === 1) {
                $pendingLabel = $trimmed;

                continue;
            }

            if ($pendingLabel !== null) {
                $kept[] = $pendingLabel;
                $pendingLabel = null;
            }
            $kept[] = $line;
        }

        if ($pendingLabel !== null) {
            $kept[] = $pendingLabel;
        }

        $text = implode("\n", $kept);
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * @return array<string, string> url => label
     */
    private function harvestUrlLabels(string $text): array
    {
        if ($text === '' || ! preg_match_all('#https?://[^\s<>"\']+#iu', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $labels = [];
        foreach ($matches[0] as [$raw, $offset]) {
            $url = rtrim($raw, ".,);]}>\"'");
            $start = max(0, $offset - 160);
            $before = substr($text, $start, $offset - $start);
            $parts = preg_split("/\r\n|\n|\r/u", $before) ?: [$before];
            $prefix = trim((string) end($parts));
            $prefix = $this->normalizeLinkLabel($prefix);

            if ($prefix === '') {
                continue;
            }

            $labels[$url] = $prefix;
        }

        return $labels;
    }

    private function fallbackLabelForUrl(string $url): string
    {
        $lower = strtolower($url);

        if ($this->isMeetingJoinUrl($url)) {
            return 'Meeting join link';
        }
        if (str_contains($lower, 'meetingrecap') || str_contains($lower, 'recap')) {
            return 'Teams recording';
        }
        if (str_contains($lower, 'youtu')) {
            return 'YouTube recording';
        }
        if (str_contains($lower, 'facebook.com') && str_contains($lower, 'video')) {
            return 'Facebook recording';
        }
        if (str_contains($lower, 'sharepoint') || str_contains($lower, 'stream')) {
            return 'SharePoint / Stream recording';
        }
        if (str_contains($lower, 'drive.google.com')) {
            return 'Google Drive file';
        }
        if (str_contains($lower, 'docs.google.com')) {
            return 'Google Doc';
        }

        return 'Shared link';
    }

    /**
     * @param  list<string>  $urls
     * @param  array<string, string>  $labels
     */
    private function formatLabeledLinkList(array $urls, array $labels): string
    {
        // Caller already filtered to the right URL class; only dedupe here.
        $byKey = [];
        foreach ($urls as $url) {
            $url = rtrim($url, ".,);]}>\"'");
            if ($url === '') {
                continue;
            }
            $key = $this->urlDedupeKey($url);
            if (! isset($byKey[$key])) {
                $byKey[$key] = $url;
            } else {
                $byKey[$key] = $this->preferCleanerStoredUrl($byKey[$key], $url);
            }
        }
        $urls = array_values($byKey);
        $lines = [];
        foreach ($urls as $i => $url) {
            $key = $this->urlDedupeKey($url);
            $label = $this->normalizeLinkLabel((string) ($labels[$key] ?? $labels[$url] ?? ''));
            if ($label === '') {
                $label = $this->fallbackLabelForUrl($url);
            }
            if ($i > 0) {
                $lines[] = '';
            }
            $lines[] = ($i + 1).'. '.$label;
            $lines[] = $url;
        }

        return implode("\n", $lines);
    }

    /**
     * If a reply is only a numbered list, add a short natural lead sentence.
     */
    private function ensureListHasIntro(string $answer, string $question, string $linkMode = 'none'): string
    {
        $trimmed = ltrim($answer);
        if ($trimmed === '') {
            return $answer;
        }

        // Already has a prose lead before the list.
        if (preg_match('/^\d+\.\s/u', $trimmed) !== 1) {
            return $answer;
        }

        $mode = $this->resolveUrlListMode($question, $linkMode);
        $intro = $this->linkListIntro($question, $mode);

        return $intro."\n\n".$trimmed;
    }

    /**
     * @param  'none'|'recordings'|'meetings'|'assets'  $mode
     */
    private function linkListIntro(string $question, string $mode = 'none'): string
    {
        $fr = $this->looksLikeFrenchQuery($question);
        if ($mode === 'meetings') {
            return $fr ? 'Voici les liens de réunion :' : 'Here are the meeting join links:';
        }
        if ($mode === 'recordings') {
            return $fr ? 'Voici les enregistrements des sessions :' : 'Here are the session recordings:';
        }
        if ($mode === 'assets') {
            return $fr ? 'Voici les liens :' : 'Here are the links:';
        }

        return $fr ? 'Les voici :' : 'Here they are:';
    }

    private function looksLikeFrenchQuery(string $query): bool
    {
        $q = mb_strtolower($query);

        return (bool) preg_match(
            '/\b(bonjour|merci|s\'il vous|envoyez|lien|liens|enregistrement|enregistrements|r[eé]union|vid[eé]o|vid[eé]os|aujourd\'hui|est-ce|y a-t-il)\b/u',
            $q
        ) || (bool) preg_match('/[àâäçéèêëïîôùûüÿœ]/u', $query);
    }

    /**
     * @param  list<string>  $urls
     */
    private function formatLinkList(array $urls, ?string $kind = null): string
    {
        return $this->formatLabeledLinkList($urls, []);
    }

    private function looksLikeLinkRequest(string $query): bool
    {
        $q = mb_strtolower($query);

        // English-only offline fallback. Multilingual asks use model link_mode.
        return (bool) preg_match(
            '/\b(recording|recordings|youtube|youtu|link|links|slides|video|videos|replay|recap|url|urls)\b/u',
            $q
        );
    }

    /**
     * @return list<string>
     */
    private function extractHttpUrls(string $text): array
    {
        if ($text === '') {
            return [];
        }

        preg_match_all('#https?://[^\s<>"\']+#iu', $text, $matches);
        $urls = [];
        foreach ($matches[0] as $raw) {
            $url = rtrim($raw, ".,);]}>\"'");
            $lower = strtolower($url);
            if (str_starts_with($lower, 'http://') || str_starts_with($lower, 'https://')) {
                $urls[$url] = true;
            }
        }

        return array_keys($urls);
    }

    private function friendlySourceName(string $name): string
    {
        $lower = strtolower($name);
        if (str_contains($lower, 'whatsapp') || str_contains($lower, 'unipods')) {
            return 'the UniPods community chat';
        }
        if (str_contains($lower, 'clinic')) {
            return 'clinic hours notes';
        }

        return $name;
    }

    private function handleMemberAssets(InboundMessage $message): string
    {
        $community = $this->resolveLinkedCommunity($message);
        if ($community === null) {
            return 'Please link a community first with /join, then try /assets again.';
        }

        $arg = $this->listenGate->slashCommandBody($message->text, 'assets');

        return app(ProgramAssetRegistrar::class)->memberCatalogReply($community, $arg, 'plain');
    }

    private function handleShareStub(InboundMessage $message): string
    {
        $user = $this->resolveUser();
        $link = Cache::get($this->linkCacheKey($message->externalUserId));
        $communityId = is_array($link)
            ? (string) ($link['community_id'] ?? '')
            : (string) config('telegram_spike.default_community_id');

        if ($user === null || $communityId === '') {
            return "Please link a community first with /join, then try /share again.";
        }

        if (! $user->belongsToCommunity($communityId)) {
            return "It looks like you're not a member of that community in "
                .$this->conversation->botDisplayName().' yet. An admin can add you.';
        }

        $community = Community::query()->findOrFail($communityId);
        $body = $this->listenGate->slashCommandBody($message->text, 'share');

        if ($body === '') {
            return "Share something the community should know, like:\n"
                ."/share Water off tomorrow morning\n\n"
                .'An admin will review it before '
                .$this->conversation->botDisplayName().' can use it in answers.';
        }

        $source = $this->lifecycle->import($user, [
            'tenant_id' => $community->tenant_id,
            'community_id' => $communityId,
            'name' => 'Shared Telegram note',
            'uri' => 'telegram-spike://forward/'.Str::ulid(),
            'source_type' => 'telegram',
            'content' => $body,
            'metadata' => [
                'channel' => 'telegram_spike',
                'from' => $message->externalUserId,
            ],
        ]);

        $this->lifecycle->submitForReview($user, $source);

        $fromName = trim((string) ($message->raw['from_name'] ?? ''));
        if ($fromName === '' && isset($message->raw['from_username'])) {
            $fromName = '@'.ltrim((string) $message->raw['from_username'], '@');
        }

        $notify = $this->escalationNotifier->notifyShareReview(
            channel: $this->channelName(),
            from: $message->externalUserId,
            content: $body,
            knowledgeSourceId: (string) $source->id,
            communityId: $communityId,
            communityName: $community->name,
            fromName: $fromName !== '' ? $fromName : null,
        );

        Log::info('telegram_spike.share_draft', [
            'knowledge_id' => $source->id,
            'notified' => $notify['notified'],
        ]);

        if ($notify['notified']) {
            return $this->conversation->shareQueuedReply(true);
        }

        return $this->conversation->shareQueuedReply(false);
    }

    private function handleFeature(InboundMessage $message): string
    {
        $community = $this->resolveLinkedCommunity($message);
        if ($community === null) {
            return 'Please link a community first with /join, then try /feature again.';
        }

        $body = $this->listenGate->slashCommandBody($message->text, 'feature');

        if ($body === '') {
            return $this->conversation->featureUsageReply('plain');
        }

        $fromName = trim((string) ($message->raw['from_name'] ?? ''));
        if ($fromName === '' && isset($message->raw['from_username'])) {
            $fromName = '@'.ltrim((string) $message->raw['from_username'], '@');
        }

        $notify = $this->escalationNotifier->notifyFeatureRequest(
            channel: $this->channelName(),
            from: $message->externalUserId,
            content: $body,
            communityId: $community->id,
            communityName: $community->name,
            fromName: $fromName !== '' ? $fromName : null,
            chatType: strtolower(trim((string) ($message->raw['chat_type'] ?? 'private'))),
            chatId: trim((string) ($message->raw['chat_id'] ?? '')) ?: null,
            messageId: $message->messageId,
        );

        Log::info('telegram_spike.feature_request', [
            'ref' => $notify['ref'] ?? null,
            'notified' => $notify['notified'],
        ]);

        return $this->conversation->featureQueuedReply((bool) ($notify['notified'] ?? false));
    }

    private function tryAdminAutoIndex(InboundMessage $message): ?string
    {
        $user = $this->resolveUser();
        $community = $this->resolveLinkedCommunity($message);
        if ($user === null || $community === null) {
            return null;
        }

        $result = $this->adminIndexer->maybeIndex(
            $this->channelName(),
            $message->text,
            $user,
            $community,
            $message->externalUserId,
        );

        return ($result['indexed'] ?? false) ? ($result['reply'] ?? null) : null;
    }

    /**
     * Admin-only ingest (not the same as member /share).
     * /import is the command; /export remains a legacy alias.
     */
    private function handleAdminImport(InboundMessage $message): string
    {
        $access = app(ChannelCommandAccess::class);
        if (! $access->isAdmin($this->channelName(), $message->externalUserId)) {
            return $access->adminOnlyDenial();
        }

        $user = $this->resolveUser();
        $link = Cache::get($this->linkCacheKey($message->externalUserId));
        $communityId = is_array($link)
            ? (string) ($link['community_id'] ?? '')
            : (string) config('telegram_spike.default_community_id');

        if ($user === null || $communityId === '') {
            return 'Link a community with /join before /import.';
        }

        if (! $user->belongsToCommunity($communityId)) {
            return 'You are not a member of that community in '.$this->conversation->botDisplayName().'.';
        }

        $community = Community::query()->findOrFail($communityId);
        $body = trim($message->text);
        foreach (['/IMPORT', 'IMPORT', '/EXPORT', 'EXPORT'] as $prefix) {
            if (str_starts_with(strtoupper($body), $prefix)) {
                $body = trim(substr($body, strlen($prefix)));
                break;
            }
        }

        if ($body === '') {
            return 'Usage: /import <pasted chat export text to ingest as a knowledge draft>';
        }

        $source = $this->lifecycle->import($user, [
            'tenant_id' => $community->tenant_id,
            'community_id' => $communityId,
            'name' => $this->knowledgeDesk->suggestImportTitle($body),
            'uri' => 'telegram-spike://import/'.Str::ulid(),
            'source_type' => 'telegram',
            'content' => $body,
            'metadata' => [
                'channel' => 'telegram_spike',
                'from' => $message->externalUserId,
                'origin' => 'admin_import',
            ],
        ]);

        Log::info('telegram_spike.admin_import_draft', ['knowledge_id' => $source->id]);

        return $this->knowledgeDesk->draftCreatedReply($source, 'plain');
    }

    /**
     * Admin replied to an import draft card → publish without typing the ID.
     */
    private function tryAdminDraftPublishSwipe(InboundMessage $message): ?string
    {
        if (! $this->commandAccess->isAdmin(
            $this->channelName(),
            $message->externalUserId,
            is_array($message->raw) ? $message->raw : [],
        )) {
            return null;
        }

        $replyToBot = filter_var($message->raw['reply_to_bot'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (! $replyToBot) {
            return null;
        }

        $quoted = trim((string) ($message->raw['quoted_text'] ?? ''));
        if ($quoted === '' || ! $this->knowledgeDesk->isDraftCardText($quoted)) {
            return null;
        }

        if (! $this->knowledgeDesk->isPublishConfirmText($message->text)) {
            return null;
        }

        $short = $this->knowledgeDesk->extractShortIdFromDraftCard($quoted);
        if ($short === null || $short === '') {
            return "I couldn't read the draft ID from that reply.\n\nSend /publish latest";
        }

        $user = $this->resolveUser();
        $community = $this->resolveLinkedCommunity($message);
        if ($user === null || $community === null) {
            return 'Link a community with /join before publishing.';
        }

        $result = $this->knowledgeDesk->tryHandle('/publish '.$short, $user, $community, 'plain');

        return (string) ($result['reply'] ?? 'Published.');
    }

    private function handleAdminKnowledgeDesk(InboundMessage $message): string
    {
        if (! $this->commandAccess->isAdmin($this->channelName(), $message->externalUserId)) {
            return $this->commandAccess->adminOnlyDenial('plain');
        }

        $user = $this->resolveUser();
        $link = Cache::get($this->linkCacheKey($message->externalUserId));
        $communityId = is_array($link)
            ? (string) ($link['community_id'] ?? '')
            : (string) config('telegram_spike.default_community_id');

        if ($user === null || $communityId === '') {
            return 'Link a community with /join before using knowledge admin commands.';
        }

        $community = Community::query()->find($communityId);
        if ($community === null) {
            return 'I could not find that linked community.';
        }

        $result = $this->knowledgeDesk->tryHandle($message->text, $user, $community, 'plain');
        if ($result === null) {
            return 'Try /publish, /knowledge, or /features.';
        }

        return (string) ($result['reply'] ?? 'Done.');
    }

    private function handleAdminAsset(InboundMessage $message): string
    {
        if (! $this->commandAccess->isAdmin($this->channelName(), $message->externalUserId)) {
            return $this->commandAccess->adminOnlyDenial('plain');
        }

        $user = $this->resolveUser();
        $community = $this->resolveLinkedCommunity($message);
        if ($user === null || $community === null) {
            return 'Link a community with /join before /asset.';
        }

        $result = app(ProgramAssetRegistrar::class)->registerFromCommand(
            $this->channelName(),
            $message->text,
            $user,
            $community,
            'plain',
        );

        return (string) ($result['reply'] ?? 'Done.');
    }

    private function resolveUser(): ?User
    {
        $email = (string) config('telegram_spike.default_user_email');
        if ($email === '') {
            return null;
        }

        return User::query()->where('email', $email)->first();
    }

    private function utf8Safe(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        if (! is_string($clean)) {
            $clean = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $clean) ?? $clean;

        $encoded = json_encode($clean, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return '';
        }

        $decoded = json_decode($encoded, true);

        return is_string($decoded) ? $decoded : '';
    }

    private function linkCacheKey(string $externalUserId): string
    {
        return 'telegram_spike_link:'.sha1($externalUserId);
    }

    public static function mintJoinToken(string $communityId, ?int $ttlHours = null): string
    {
        $ttl = $ttlHours ?? (int) config('telegram_spike.join_token_ttl_hours', 72);
        $token = Str::lower((string) Str::ulid());
        Cache::put('telegram_spike_join:'.$token, $communityId, now()->addHours($ttl));

        return $token;
    }
}
