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

/**
 * WhatsApp Web spike: same AI brain as Telegram; listen/access rules differ.
 */
final class WhatsAppWebSpikeAdapter implements ChannelAdapter
{
    public function __construct(
        private readonly AiServiceClient $aiClient,
        private readonly CitationRevalidator $citationRevalidator,
        private readonly KnowledgeLifecycleService $lifecycle,
        private readonly ChannelConversationService $conversation,
        private readonly SpikeEscalationNotifier $escalationNotifier,
        private readonly ChannelListenGate $listenGate,
        private readonly ChannelCommandAccess $commandAccess,
        private readonly AdminMessageKnowledgeIndexer $adminIndexer,
    ) {}

    public function channelName(): string
    {
        return 'whatsapp_web_spike';
    }

    public function handleInbound(InboundMessage $message): ?string
    {
        try {
            $reply = $this->dispatchInbound($message);
            if ($reply === null || $reply === '') {
                return $reply;
            }

            return $this->formatWhatsAppCommands($reply);
        } catch (\Throwable $e) {
            Log::error('whatsapp_web_spike.inbound_failed', [
                'from' => $message->externalUserId,
                'error' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);

            return "I hit a snag answering that just now. "
                .'Mind sending it again in a moment?';
        }
    }

    private function dispatchInbound(InboundMessage $message): ?string
    {
        if ($message->text === '') {
            return null;
        }

        $chatType = strtolower(trim((string) ($message->raw['chat_type'] ?? 'private')));
        $chatId = trim((string) ($message->raw['chat_id'] ?? ''));
        // Never treat a group JID as a private always-listen chat.
        if (str_ends_with($chatId, '@g.us') || filter_var($message->raw['is_group'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $chatType = 'group';
        }

        $aliases = $this->botAliases($message);
        $mode = (string) config('whatsapp_web_spike.group_listen', 'mention_or_command');
        $botMentioned = filter_var($message->raw['bot_mentioned'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || filter_var($message->raw['reply_to_bot'] ?? false, FILTER_VALIDATE_BOOLEAN);
        // Text still contains @zak / @LID even when the sidecar omitted bot_mentioned.
        if (! $botMentioned && $this->listenGate->containsAlias($message->text, $aliases)) {
            $botMentioned = true;
        }

        // Early admin check so group listen can include configured admins.
        $isAdmin = $this->commandAccess->isAdmin(
            $this->channelName(),
            $message->externalUserId,
            array_merge(
                is_array($message->raw) ? $message->raw : [],
                ['text' => $message->text],
            ),
        );

        // WA group @-mentions often use the bot LID (@1006…), not the phone — sidecar detects that.
        // Replying to a bot message also counts as engaging the bot (no @ required).
        if (! $botMentioned && ! $this->listenGate->shouldListen(
            $chatType,
            $message->text,
            $aliases,
            $mode,
            $isAdmin,
        )) {
            return null;
        }
        if ($botMentioned && $chatType !== 'private' && $chatType !== 'dm' && $chatType !== '') {
            // still apply private_only / off
            if ($mode === 'off' || $mode === 'private_only') {
                return null;
            }
        }

        // Resolve @member mentions to display names before routing/retrieval.
        $mentionRows = is_array($message->raw['mentions'] ?? null) ? $message->raw['mentions'] : [];
        $botIds = array_values(array_filter([
            (string) config('whatsapp_web_spike.bot_number', ''),
            (string) config('whatsapp_web_spike.bot_lid', ''),
            (string) ($message->raw['bot_number'] ?? ''),
            (string) ($message->raw['bot_lid'] ?? ''),
        ]));
        $expanded = $this->conversation->expandMentionedPeople($message->text, $mentionRows, $botIds);
        if ($expanded !== $message->text) {
            $message = new InboundMessage(
                channel: $message->channel,
                externalUserId: $message->externalUserId,
                text: $expanded,
                messageId: $message->messageId,
                raw: $message->raw,
            );
        }

        $stripped = $this->listenGate->stripMentions($message->text, $aliases);
        if ($stripped !== $message->text) {
            // Keep bot_mentioned=true after stripping @zak / @LID from the body.
            $raw = is_array($message->raw) ? $message->raw : [];
            $raw['bot_mentioned'] = true;
            $message = new InboundMessage(
                channel: $message->channel,
                externalUserId: $message->externalUserId,
                text: $stripped,
                messageId: $message->messageId,
                raw: $raw,
            );
            $botMentioned = true;
        }

        // Bare @bot / @LID only: stripMentions leaves "". Keep a ping token so
        // quote-reply ("answer this") and friendly bare-ping still run.
        if ($message->text === '') {
            $quoted = trim((string) ($message->raw['quoted_text'] ?? ''));
            $replyToBot = filter_var($message->raw['reply_to_bot'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if (! $botMentioned && ! $replyToBot && $quoted === '') {
                return null;
            }

            $message = new InboundMessage(
                channel: $message->channel,
                externalUserId: $message->externalUserId,
                text: '@zak',
                messageId: $message->messageId,
                raw: $message->raw,
            );
        }

        $isAdmin = $this->commandAccess->isAdmin(
            $this->channelName(),
            $message->externalUserId,
            array_merge(
                is_array($message->raw) ? $message->raw : [],
                ['text' => $message->text],
            ),
        );

        if ($isAdmin) {
            $cardReply = $this->tryAdminCardReply($message);
            if ($cardReply !== null) {
                return $cardReply;
            }

            $adminCmd = $this->escalationNotifier->tryAdminCommand(
                $message->text,
                $this->channelName(),
                ...$this->adminActor($message),
            );
            if ($adminCmd !== null) {
                return (string) ($adminCmd['reply'] ?? 'Done.');
            }
        } elseif ($this->commandAccess->isAdminOnlyCommand($message->text)) {
            return $this->commandAccess->adminOnlyDenial('whatsapp');
        }

        $upper = strtoupper($message->text);

        if (str_starts_with($upper, 'JOIN-') || str_starts_with($upper, '/JOIN')) {
            return $this->handleJoin($message);
        }

        if (str_starts_with($upper, '/HELP') || $upper === 'HELP'
            || str_starts_with($upper, '/START') || $upper === 'START') {
            return $this->helpText($isAdmin, $message);
        }

        if (str_starts_with($upper, 'SHARE') || str_starts_with($upper, '/SHARE')) {
            return $this->handleShare($message);
        }

        if (str_starts_with($upper, 'FEATURE') || str_starts_with($upper, '/FEATURE')) {
            return $this->handleFeature($message);
        }

        if (str_starts_with($upper, 'IMPORT') || str_starts_with($upper, '/IMPORT')
            || str_starts_with($upper, 'EXPORT') || str_starts_with($upper, '/EXPORT')) {
            return $this->handleAdminImport($message);
        }

        if (str_starts_with($upper, 'ASK') || str_starts_with($upper, '/ASK')) {
            return $this->handleMemberAsk($message);
        }

        // Admin plain messages: model may auto-publish durable facts to knowledge.
        $adminIndexAck = null;
        if ($isAdmin) {
            $adminIndexAck = $this->tryAdminAutoIndex($message);
        }

        // Group @ / swipe-reply: only answer when the turn is actually for Zak.
        // Admins we already listened to may still get a quiet knowledge ack.
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
            if ($this->conversation->isBareBotPing($inboundText)) {
                $reply = $this->conversation->mentionPingReply(
                    'whatsapp',
                    'whatsapp',
                    $this->chatType($message),
                );
            } else {
                $reply = $this->modelAssistedReply($message, 'social', $community);
                if ($reply === '') {
                    $reply = $this->conversation->mentionPingReply(
                        'whatsapp',
                        'whatsapp',
                        $this->chatType($message),
                    );
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

        return $this->handleAsk(
            $message,
            $effectiveQuery,
            linkMode: (string) ($resolved['link_mode'] ?? 'none'),
        );
    }

    /**
     * Same hybrid cascade as Telegram: rules for social + hard OOS; model otherwise.
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

    /**
     * Growth / study / motivate: answer in private; nudge + DM link in groups.
     */
    private function handlePersonalHelp(InboundMessage $message, ?Community $community): string
    {
        $chatType = $this->chatType($message);
        $mode = $chatType === 'group' ? 'take_private' : 'personal_help';
        $reply = $this->modelAssistedReply($message, $mode, $community);
        $this->rememberTurn($message, 'user', $message->text);
        $this->rememberTurn($message, 'assistant', $reply);

        return $reply;
    }

    /**
     * Prefer AI reply (matches member language) — same brain as Telegram.
     * Fall back to short local templates only when AI is down.
     */
    private function modelAssistedReply(InboundMessage $message, string $mode, ?Community $community): string
    {
        $chatType = $this->chatType($message);
        if ($mode === 'social' && $this->conversation->isChannelPresenceAsk($message->text)) {
            return $this->conversation->channelPresenceReply('whatsapp', 'whatsapp', $chatType);
        }

        $ctx = $this->communityAiContext($community);
        $reply = $this->aiClient->conversationalReply(
            message: $message->text,
            mode: $mode,
            communityName: $ctx['name'],
            communityScope: $ctx['scope'],
            targetLanguage: $this->targetLanguageOverride($message),
        );

        if ($reply !== '') {
            if (
                $mode === 'out_of_scope'
                && $this->conversation->shouldAppendEnglishAskHint($reply, $message->text)
            ) {
                return rtrim($reply)."\n\n".$this->conversation->askAdminHint('whatsapp');
            }

            if ($mode === 'take_private') {
                return $this->conversation->withPrivateChatLink(
                    $reply,
                    'whatsapp',
                    'whatsapp',
                );
            }

            return $reply;
        }

        if ($mode === 'take_private') {
            return $this->conversation->takePrivateFallbackReply('whatsapp', 'whatsapp');
        }

        if ($mode === 'out_of_scope') {
            return $this->conversation->outOfScopeReply(
                $community?->name,
                $community?->description,
                'whatsapp',
            );
        }

        if ($mode === 'personal_help') {
            return $this->conversation->personalHelpFallbackReply();
        }

        return $this->conversation->conversationalReply(
            $message->text,
            'whatsapp',
            'whatsapp',
            $chatType,
        );
    }

    private function chatType(InboundMessage $message): string
    {
        $chatType = strtolower(trim((string) ($message->raw['chat_type'] ?? 'private')));

        return in_array($chatType, ['group', 'supergroup'], true) ? 'group' : 'private';
    }

    private function targetLanguageOverride(InboundMessage $message): ?string
    {
        $override = $message->raw['target_language'] ?? null;
        if (! is_string($override)) {
            return null;
        }

        $lang = trim($override);

        return $lang !== '' && strtolower($lang) !== 'auto' ? $lang : null;
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
            Log::info('whatsapp_web_spike.skip_undirected', [
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
            Log::info('whatsapp_web_spike.skip_undirected_ai', [
                'from' => $message->externalUserId,
            ]);

            return false;
        }

        // AI unavailable: allow clear swipe-replies; otherwise stay silent.
        return $replyToBot && mb_strlen(trim($message->text)) >= 2;
    }

    private function helpText(bool $isAdmin = false, ?InboundMessage $message = null): string
    {
        $chatType = $message !== null ? $this->chatType($message) : 'private';
        // Admin commands only in private DM — never expose them in group /help.
        $showAdmin = $isAdmin && $chatType === 'private';

        return $this->conversation->helpTextFor('whatsapp', 'whatsapp', $showAdmin, $chatType);
    }

    /**
     * Admin swipe-replied to an escalation card: plain answer = reply;
     * /approve /decline /blacklist /reply work without typing the Request ID.
     */
    /**
     * @return array{0: string, 1: string|null}  [sender id, resolved admin phone digits]
     */
    private function adminActor(InboundMessage $message): array
    {
        $id = $message->externalUserId;
        $phone = $this->commandAccess->resolveWhatsAppAdminPhone(
            $id,
            is_array($message->raw) ? $message->raw : [],
        );

        return [$id, $phone];
    }

    private function tryAdminCardReply(InboundMessage $message): ?string
    {
        $quoted = trim((string) ($message->raw['quoted_text'] ?? ''));
        if ($quoted === '' || ! filter_var($message->raw['reply_to_bot'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        if (preg_match('/Request ID:\s*([A-Z0-9]+)/i', $quoted, $m) !== 1) {
            return null;
        }

        $ref = strtoupper(trim($m[1]));
        $body = trim($message->text);
        if ($body === '') {
            return null;
        }

        [$actorId, $actorPhone] = $this->adminActor($message);

        // /approve | /decline | /reject  (± REF already)
        if (preg_match('/^\/?(approve|decline|reject)(?:\s+(\S+))?$/iu', $body, $m) === 1) {
            $action = strtolower($m[1]);
            $cmd = $this->escalationNotifier->tryAdminCommand(
                "/{$action} {$ref}",
                $this->channelName(),
                $actorId,
                $actorPhone,
            );

            return is_array($cmd) ? (string) ($cmd['reply'] ?? 'Done.') : null;
        }

        // /blacklist | /unblacklist  (± REF already)
        if (preg_match('/^\/?(blacklist|unblacklist)(?:\s+(\S+))?$/iu', $body, $m) === 1) {
            $action = strtolower($m[1]);
            $cmd = $this->escalationNotifier->tryAdminCommand(
                "/{$action} {$ref}",
                $this->channelName(),
                $actorId,
                $actorPhone,
            );

            return is_array($cmd) ? (string) ($cmd['reply'] ?? 'Done.') : null;
        }

        if (preg_match('/^\/?reply\b/iu', $body) === 1) {
            $rest = trim((string) preg_replace('/^\/?reply\s+/iu', '', $body));
            if ($rest === '') {
                return "Type the answer in this swipe-reply, or:\n```\n/reply {$ref} Your answer here\n```";
            }
            if (str_starts_with(strtoupper($rest), $ref.' ')) {
                return null; // already has REF — let tryAdminCommand handle
            }
            $cmd = $this->escalationNotifier->tryAdminCommand(
                "/reply {$ref} {$rest}",
                $this->channelName(),
                $actorId,
                $actorPhone,
            );

            return is_array($cmd) ? (string) ($cmd['reply'] ?? 'Done.') : null;
        }

        // Any other admin slash command: don't steal it
        if (preg_match('/^\/[a-z]+/iu', $body) === 1) {
            return null;
        }

        // Plain text while quoting the card = the answer
        $cmd = $this->escalationNotifier->tryAdminCommand(
            "/reply {$ref} {$body}",
            $this->channelName(),
            $actorId,
            $actorPhone,
        );

        return is_array($cmd) ? (string) ($cmd['reply'] ?? 'Done.') : null;
    }

    /**
     * Make known /commands stand out in WhatsApp (monospace).
     * Only wrap real bot commands — never path segments inside https://… URLs
     * (wrapping /t in t.me or /api in whatsapp.com broke links and hid "//").
     */
    private function formatWhatsAppCommands(string $text): string
    {
        // WhatsApp bold is *single* asterisks — Markdown **bold** shows raw stars.
        $text = $this->toWhatsAppFormatting($text);

        $formatted = preg_replace_callback(
            '/```[\s\S]*?```|\/(?:ask|share|feature|help|join|export|import|approve|decline|reply|blacklist)\b/iu',
            static function (array $m): string {
                if (str_starts_with($m[0], '```')) {
                    return $m[0];
                }

                return '```'.$m[0].'```';
            },
            $text
        );

        return is_string($formatted) ? $formatted : $text;
    }

    /**
     * Convert Markdown emphasis to WhatsApp formatting (*bold*, _italic_).
     * Double asterisks must never reach the client — they render as literal **.
     */
    private function toWhatsAppFormatting(string $text): string
    {
        // [label](url) → label + URL on next line (tappable)
        $text = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/u', "$1\n$2", $text) ?? $text;
        // **bold** → *bold*
        $text = preg_replace('/\*\*(.+?)\*\*/us', '*$1*', $text) ?? $text;
        // __bold__ → *bold*
        $text = preg_replace('/__(.+?)__/us', '*$1*', $text) ?? $text;

        return $text;
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
            $token = ltrim($raw, '-');
        }

        $communityId = Cache::pull('wa_web_spike_join:'.strtolower($token));

        if ($communityId === null || ! Community::query()->whereKey($communityId)->exists()) {
            return 'JOIN failed: invalid or expired token. Ask an admin for a new JOIN link.';
        }

        $user = $this->resolveUser();
        if ($user === null) {
            return 'JOIN failed: no spike user configured (set WHATSAPP_WEB_SPIKE_DEFAULT_USER_EMAIL).';
        }

        Cache::forever($this->linkCacheKey($message->externalUserId), [
            'user_id' => $user->id,
            'community_id' => $communityId,
        ]);

        return 'Linked to community '.$communityId.'. Ask me a question about community knowledge.';
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
            $reply = "Ask a community question like this:\n"
                ."```\n"
                ."/ask When is the next UniPods session?\n"
                ."```\n\n"
                .'Use this for schedules, updates, and what has been shared here. '
                .'If I already know the answer, I\'ll reply right away; '
                .'otherwise I\'ll look into it and follow up.';
            $this->rememberTurn($message, 'user', $message->text);
            $this->rememberTurn($message, 'assistant', $reply);

            return $reply;
        }

        $answered = $this->tryGroundedAnswer($message, $this->withQuotedContext($message, $body));
        if ($answered !== null) {
            $this->rememberTurn($message, 'user', $message->text);
            $this->rememberTurn($message, 'assistant', $answered);

            return $answered;
        }

        $community = $this->resolveLinkedCommunity($message);
        $communityId = $community?->id
            ?? (string) config('whatsapp_web_spike.default_community_id', '');

        $fromName = trim((string) ($message->raw['from_name'] ?? ''));
        $fromPhone = trim((string) ($message->raw['from_phone'] ?? ''));

        $result = $this->escalationNotifier->handleMemberAsk(
            channel: $this->channelName(),
            from: $message->externalUserId,
            question: $this->withQuotedContext($message, $body),
            communityId: (string) $communityId,
            communityName: $community?->name,
            fromName: $fromName !== '' ? $fromName : null,
            fromPhone: $fromPhone !== '' ? $fromPhone : null,
            chatType: $this->chatType($message),
            chatId: trim((string) ($message->raw['chat_id'] ?? '')) ?: null,
            messageId: $message->messageId,
        );

        $reply = (string) ($result['reply'] ?? 'Done.');
        $this->rememberTurn($message, 'user', $message->text);
        $this->rememberTurn($message, 'assistant', $reply);

        return $reply;
    }

    private function handleAsk(
        InboundMessage $message,
        ?string $query = null,
        string $linkMode = 'none',
    ): string {
        $query = trim((string) ($query ?? $message->text));
        // Follow-ups on our own reply already carry session context — do not
        // re-paste the quoted bot answer into the retrieval query.
        if (! $this->shouldSkipQuotedFold($message, $query)) {
            $query = $this->withQuotedContext($message, $query);
        }
        $answered = $this->tryGroundedAnswer($message, $query, $linkMode);
        if ($answered !== null) {
            $this->rememberTurn(
                $message,
                'user',
                $this->conversation->userTurnTextToRemember($message->text, $query),
            );
            $this->rememberTurn($message, 'assistant', $answered);

            return $answered;
        }

        $user = $this->resolveUser();
        $community = $this->resolveLinkedCommunity($message);
        if ($user === null) {
            return 'Spike identity missing. Set WHATSAPP_WEB_SPIKE_DEFAULT_USER_EMAIL to demo@zak.test.';
        }
        if ($community === null) {
            return 'No community linked. Send a minted JOIN token first (or set WHATSAPP_WEB_SPIKE_DEFAULT_COMMUNITY_ID).';
        }
        if (! $user->belongsToCommunity($community->id)) {
            return 'You are not a member of that community in '.$this->conversation->botDisplayName().'.';
        }

        $shouldEscalate = $this->conversation->shouldEscalateKnowledgeGap(
            $query,
            $community->description,
        );

        if ($shouldEscalate) {
            $fromName = trim((string) ($message->raw['from_name'] ?? ''));
            $this->escalationNotifier->escalate([
                'question' => $query,
                'from' => $message->externalUserId,
                'from_name' => $fromName !== '' ? $fromName : null,
                'from_phone' => trim((string) ($message->raw['from_phone'] ?? '')) ?: null,
                'chat_type' => $this->chatType($message),
                'chat_id' => trim((string) ($message->raw['chat_id'] ?? '')) ?: null,
                'message_id' => $message->messageId,
                'community_id' => $community->id,
                'community_name' => $community->name,
                'reason' => 'insufficient_evidence',
                'channel' => $this->channelName(),
            ]);

            $reply = "I don't have a solid answer for that yet.\n\n"
                ."I've passed it along, and I'll follow up once I have one. "
                .'No need to keep checking or asking again.';
        } else {
            // Tone / social must never look like an admin handoff.
            $mode = ($this->conversation->isPurelySocial($query)
                || $this->conversation->isBotDirectedChat($query))
                ? 'social'
                : 'out_of_scope';
            $reply = $this->modelAssistedReply($message, $mode, $community);
        }

        $this->rememberTurn(
            $message,
            'user',
            $this->conversation->userTurnTextToRemember($message->text, $query),
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

    private function tryGroundedAnswer(
        InboundMessage $message,
        string $query,
        string $linkMode = 'none',
    ): ?string {
        $user = $this->resolveUser();
        $community = $this->resolveLinkedCommunity($message);
        if ($user === null || $community === null) {
            return null;
        }
        if (! $user->belongsToCommunity($community->id)) {
            return null;
        }

        $allowedLink = ['none', 'recordings', 'meetings', 'assets'];
        if (! in_array($linkMode, $allowedLink, true)) {
            $linkMode = 'none';
        }
        if ($linkMode === 'none') {
            $linkMode = $this->conversation->inferLinkMode($query);
        }

        $priorTurns = $this->priorTurns($message);
        $knowledgeQuery = $this->conversation->buildKnowledgeQuery($query, $priorTurns);

        $result = $this->aiClient->askGroundedQuestion(
            query: $knowledgeQuery,
            tenantId: $community->tenant_id,
            communityIds: [$community->id],
            targetLanguage: $this->targetLanguageOverride($message),
            linkMode: $linkMode,
        );

        $result = $this->citationRevalidator->revalidate($user, $result);

        if ($this->isTransientAiFailure($result)) {
            return 'I hit a snag answering that just now. Mind sending it again in a moment?';
        }

        if (trim((string) $result->answer) === '') {
            \Illuminate\Support\Facades\Log::info('whatsapp_web_spike.grounded_empty', [
                'query_len' => mb_strlen($knowledgeQuery),
                'link_mode' => $linkMode,
                'state' => $result->state->value,
                'escalation_reason' => $result->escalationReason,
                'citations' => count($result->citations),
                'chunks' => $result->totalChunksRetrieved,
            ]);

            return null;
        }

        $answer = $this->memberFacingAnswer((string) $result->answer);

        // No English "(From …)" footer — it mixes languages with non-English replies.
        return $answer;
    }

    /**
     * Strip internal markers members should never see.
     */
    private function memberFacingAnswer(string $answer): string
    {
        return $this->conversation->tidyMemberAnswer($answer);
    }

    /**
     * Short member-facing source label (no raw dump titles / internal states).
     */
    private function friendlySourceName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        $lower = strtolower($name);
        if (str_contains($lower, 'whatsapp') || str_contains($lower, 'unipods')) {
            return 'the UniPods community chat';
        }
        if (str_contains($lower, 'clinic')) {
            return 'clinic hours notes';
        }

        return $name;
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

    /**
     * When the member replied to a bot (or other) message, fold that quote into the query.
     * Never fold admin escalation/share cards (that causes nested "needs a quick hand" loops).
     * Never fold our own prior answer when the member is following up ("Are you sure?").
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

        if ($this->commandAccess->looksLikeEscalationCardReply(
            is_array($message->raw) ? $message->raw : []
        )) {
            return $query;
        }

        // Defensive: card markers even if reply_to_bot was missed
        $lower = mb_strtolower($quoted);
        if (str_contains($lower, 'request id:')
            && (str_contains($lower, 'needs a quick hand')
                || str_contains($lower, 'member shared a note')
                || str_contains($lower, 'member requested a feature'))) {
            return $query;
        }

        $quoted = mb_substr($quoted, 0, 800);
        $who = trim((string) ($message->raw['quoted_from'] ?? ''));
        $label = $who !== '' ? "earlier message from {$who}" : 'earlier message';

        // Already the quoted ask (from textForRouting) or already wrapped.
        if ($query === $quoted || str_starts_with($query, 'Regarding this ')
            || str_starts_with($query, 'The member is following up')) {
            return $query;
        }

        if ($query === '' || $this->isBareBotMention($query)) {
            return "Regarding this {$label}:\n\"{$quoted}\"";
        }

        return "Regarding this {$label}:\n\"{$quoted}\"\n\nCurrent message:\n{$query}";
    }

    /**
     * Swipe-reply to our own answer with a short follow-up must use session turns,
     * not re-inject the whole prior reply as the "question".
     */
    private function shouldSkipQuotedFold(InboundMessage $message, string $query): bool
    {
        $replyToBot = filter_var($message->raw['reply_to_bot'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (! $replyToBot) {
            return false;
        }

        $text = trim($message->text);
        if ($this->conversation->isContextualFollowUp($text)
            || $this->conversation->isContextualFollowUp($query)
            || str_starts_with($query, 'The member is following up')) {
            return true;
        }

        // Any swipe-reply to the bot: prefer session memory over pasting our answer.
        return true;
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
            // Quoting our own answer + bare ping → continue prior topic from session.
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

    private function isBareBotMention(string $text): bool
    {
        $stripped = trim(preg_replace(
            '/^(?:hey|hi|hello)?\s*[,:]?\s*@?zak(?:[_\s-]?bot)?\b[,:]?\s*/iu',
            '',
            trim($text),
        ) ?? '');

        return $stripped === '';
    }

    private function handleShare(InboundMessage $message): string
    {
        $user = $this->resolveUser();
        $community = $this->resolveLinkedCommunity($message);

        if ($user === null || $community === null) {
            return 'Please link a community first with /join, then try /share again.';
        }

        if (! $user->belongsToCommunity($community->id)) {
            return 'You are not a member of that community in '.$this->conversation->botDisplayName().'.';
        }

        $body = trim($message->text);
        foreach (['/SHARE', 'SHARE'] as $prefix) {
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
            'community_id' => $community->id,
            'name' => 'Shared WhatsApp note',
            'uri' => 'whatsapp-web-spike://share/'.Str::ulid(),
            'source_type' => 'whatsapp',
            'content' => $body,
            'metadata' => [
                'channel' => 'whatsapp_web_spike',
                'from' => $message->externalUserId,
            ],
        ]);

        $this->lifecycle->submitForReview($user, $source);

        $fromName = trim((string) ($message->raw['from_name'] ?? ''));
        $fromPhone = trim((string) ($message->raw['from_phone'] ?? ''));
        $notify = $this->escalationNotifier->notifyShareReview(
            channel: $this->channelName(),
            from: $message->externalUserId,
            content: $body,
            knowledgeSourceId: (string) $source->id,
            communityId: $community->id,
            communityName: $community->name,
            fromName: $fromName !== '' ? $fromName : null,
            fromPhone: $fromPhone !== '' ? $fromPhone : null,
        );

        Log::info('whatsapp_web_spike.share_draft', [
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

        $body = trim($message->text);
        foreach (['/FEATURE', 'FEATURE'] as $prefix) {
            if (str_starts_with(strtoupper($body), $prefix)) {
                $body = trim(substr($body, strlen($prefix)));
                break;
            }
        }

        if ($body === '') {
            return "Suggest a product improvement, like:\n"
                ."/feature Add reminders for upcoming sessions\n\n"
                .'An admin will review it, and I\'ll reply here when they decide.';
        }

        $fromName = trim((string) ($message->raw['from_name'] ?? ''));
        $fromPhone = trim((string) ($message->raw['from_phone'] ?? ''));
        $notify = $this->escalationNotifier->notifyFeatureRequest(
            channel: $this->channelName(),
            from: $message->externalUserId,
            content: $body,
            communityId: $community->id,
            communityName: $community->name,
            fromName: $fromName !== '' ? $fromName : null,
            fromPhone: $fromPhone !== '' ? $fromPhone : null,
            chatType: $this->chatType($message),
            chatId: trim((string) ($message->raw['chat_id'] ?? '')) ?: null,
            messageId: $message->messageId,
        );

        Log::info('whatsapp_web_spike.feature_request', [
            'ref' => $notify['ref'] ?? null,
            'notified' => $notify['notified'],
        ]);

        return $this->conversation->featureQueuedReply((bool) ($notify['notified'] ?? false));
    }

    /**
     * Model-gated: publish durable admin facts without requiring /import.
     */
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

    private function handleAdminImport(InboundMessage $message): string
    {
        if (! $this->commandAccess->isAdmin($this->channelName(), $message->externalUserId)) {
            return $this->commandAccess->adminOnlyDenial('whatsapp');
        }

        $user = $this->resolveUser();
        $community = $this->resolveLinkedCommunity($message);

        if ($user === null || $community === null) {
            return 'Link a community with a minted JOIN token before /import.';
        }

        if (! $user->belongsToCommunity($community->id)) {
            return 'You are not a member of that community in '.$this->conversation->botDisplayName().'.';
        }

        $body = trim($message->text);
        foreach (['/IMPORT', 'IMPORT', '/EXPORT', 'EXPORT'] as $prefix) {
            if (str_starts_with(strtoupper($body), $prefix)) {
                $body = trim(substr($body, strlen($prefix)));
                break;
            }
        }

        if ($body === '') {
            return 'Usage: /import <pasted chat export text>';
        }

        $source = $this->lifecycle->import($user, [
            'tenant_id' => $community->tenant_id,
            'community_id' => $community->id,
            'name' => 'WA Web spike forward',
            'uri' => 'whatsapp-web-spike://import/'.Str::ulid(),
            'source_type' => 'whatsapp',
            'content' => $body,
            'metadata' => [
                'channel' => 'whatsapp_web_spike',
                'from' => $message->externalUserId,
                'origin' => 'admin_import',
            ],
        ]);

        Log::info('whatsapp_web_spike.import_draft', ['knowledge_id' => $source->id]);

        return 'Saved as draft knowledge '.$source->id.' (submit-review → publish in Laravel).';
    }

    private function resolveLinkedCommunity(InboundMessage $message): ?Community
    {
        $link = Cache::get($this->linkCacheKey($message->externalUserId));
        $communityId = is_array($link)
            ? (string) ($link['community_id'] ?? '')
            : (string) config('whatsapp_web_spike.default_community_id');

        if ($communityId === '') {
            return null;
        }

        return Community::query()->find($communityId);
    }

    private function resolveUser(): ?User
    {
        $email = (string) config('whatsapp_web_spike.default_user_email');
        if ($email === '') {
            return null;
        }

        return User::query()->where('email', $email)->first();
    }

    private function linkCacheKey(string $externalUserId): string
    {
        return 'wa_web_spike_link:'.sha1($externalUserId);
    }

    /**
     * @return list<string>
     */
    private function botAliases(?InboundMessage $message = null): array
    {
        $out = [];

        $raw = config('whatsapp_web_spike.bot_aliases', []);
        if (is_string($raw)) {
            $out = array_merge($out, ChannelListenGate::parseAliasList($raw));
        } elseif (is_array($raw)) {
            foreach ($raw as $alias) {
                $alias = trim((string) $alias);
                if ($alias !== '') {
                    $out[] = $alias;
                }
            }
        }

        $username = trim((string) config('whatsapp_web_spike.bot_username', ''));
        if ($username !== '') {
            // Real WhatsApp mention form always includes @
            $out[] = '@'.ltrim($username, '@');
        }

        $botNumber = trim((string) config('whatsapp_web_spike.bot_number', ''));
        if ($message !== null) {
            $fromPayload = trim((string) ($message->raw['bot_number'] ?? ''));
            if ($fromPayload !== '') {
                $botNumber = $fromPayload;
            }
        }
        if ($botNumber !== '') {
            $out = array_merge($out, ChannelListenGate::phoneMentionVariants($botNumber));
        }

        // WhatsApp Linked ID — group @-mentions often look like @100696296808461
        $botLid = trim((string) ($message?->raw['bot_lid'] ?? config('whatsapp_web_spike.bot_lid', '')));
        if ($botLid !== '') {
            $lid = ltrim($botLid, '@');
            $out[] = $lid;
            $out[] = '@'.$lid;
        }

        return array_values(array_unique($out));
    }

    /**
     * Admin helper: mint a JOIN token for a community (spike).
     */
    public static function mintJoinToken(string $communityId, ?int $ttlHours = null): string
    {
        $ttl = $ttlHours ?? (int) config('whatsapp_web_spike.join_token_ttl_hours', 72);
        $token = Str::lower((string) Str::ulid());
        Cache::put('wa_web_spike_join:'.$token, $communityId, now()->addHours($ttl));

        return 'JOIN-'.$token;
    }
}
