<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\Models\Community;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Services\Knowledge\KnowledgeLifecycleService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Spike-only escalation: record the gap and notify the configured admin
 * on Telegram (used for Telegram spike and WhatsApp Zavu member /ask).
 */
final class SpikeEscalationNotifier
{
    private const ASK_RATE_MAX = 5;

    private const ASK_RATE_HOURS = 1;

    public function __construct(
        private readonly ?KnowledgeLifecycleService $lifecycle = null,
    ) {}

    private function lifecycle(): KnowledgeLifecycleService
    {
        return $this->lifecycle ?? app(KnowledgeLifecycleService::class);
    }

    /**
     * @param  array{
     *     question: string,
     *     from: string,
     *     community_id: string,
     *     reason: string,
     *     channel: string,
     *     from_name?: string|null,
     *     community_name?: string|null
     * }  $payload
     * @return array{id: string, notified: bool}
     */
    public function escalate(array $payload): array
    {
        $id = (string) Str::ulid();
        $communityName = trim((string) ($payload['community_name'] ?? ''));
        if ($communityName === '' && ($payload['community_id'] ?? '') !== '') {
            $communityName = (string) (Community::query()->whereKey($payload['community_id'])->value('name') ?? '');
        }

        $fromName = trim((string) ($payload['from_name'] ?? ''));
        if ($fromName === '') {
            $fromName = 'Member';
        }

        $record = [
            'id' => $id,
            'type' => 'ask',
            'question' => $payload['question'],
            'from' => $payload['from'],
            'from_name' => $fromName,
            'from_phone' => trim((string) ($payload['from_phone'] ?? '')),
            'chat_type' => strtolower(trim((string) ($payload['chat_type'] ?? 'private'))),
            'chat_id' => trim((string) ($payload['chat_id'] ?? '')),
            'message_id' => trim((string) ($payload['message_id'] ?? '')),
            'community_id' => $payload['community_id'],
            'community_name' => $communityName !== '' ? $communityName : 'Unknown community',
            'reason' => $payload['reason'],
            'channel' => $payload['channel'],
            'created_at' => now()->toIso8601String(),
        ];

        return $this->storeAndNotify($record);
    }

    /**
     * Member /share: draft knowledge awaiting admin /approve or /decline.
     *
     * @return array{id: string, notified: bool}
     */
    public function notifyShareReview(
        string $channel,
        string $from,
        string $content,
        string $knowledgeSourceId,
        string $communityId,
        ?string $communityName = null,
        ?string $fromName = null,
        ?string $fromPhone = null,
    ): array {
        $id = (string) Str::ulid();
        $communityName = trim((string) ($communityName ?? ''));
        if ($communityName === '' && $communityId !== '') {
            $communityName = (string) (Community::query()->whereKey($communityId)->value('name') ?? '');
        }

        $fromName = trim((string) ($fromName ?? ''));
        if ($fromName === '') {
            $fromName = 'Member';
        }

        $record = [
            'id' => $id,
            'type' => 'share_review',
            'question' => $content,
            'content' => $content,
            'knowledge_source_id' => $knowledgeSourceId,
            'from' => $from,
            'from_name' => $fromName,
            'from_phone' => trim((string) ($fromPhone ?? '')),
            'community_id' => $communityId,
            'community_name' => $communityName !== '' ? $communityName : 'Unknown community',
            'reason' => 'member_share',
            'channel' => $channel,
            'created_at' => now()->toIso8601String(),
        ];

        return $this->storeAndNotify($record);
    }

    /**
     * Member /feature: product suggestion awaiting admin /approve or /decline.
     * Not knowledge — decision is noted and the member is notified in-channel.
     *
     * @return array{id: string, notified: bool, ref: string}
     */
    public function notifyFeatureRequest(
        string $channel,
        string $from,
        string $content,
        string $communityId,
        ?string $communityName = null,
        ?string $fromName = null,
        ?string $fromPhone = null,
        ?string $chatType = null,
        ?string $chatId = null,
        ?string $messageId = null,
    ): array {
        $id = (string) Str::ulid();
        $communityName = trim((string) ($communityName ?? ''));
        if ($communityName === '' && $communityId !== '') {
            $communityName = (string) (Community::query()->whereKey($communityId)->value('name') ?? '');
        }

        $fromName = trim((string) ($fromName ?? ''));
        if ($fromName === '') {
            $fromName = 'Member';
        }

        $record = [
            'id' => $id,
            'type' => 'feature_request',
            'question' => $content,
            'content' => $content,
            'from' => $from,
            'from_name' => $fromName,
            'from_phone' => trim((string) ($fromPhone ?? '')),
            'chat_type' => trim((string) ($chatType ?? '')),
            'chat_id' => trim((string) ($chatId ?? '')),
            'message_id' => trim((string) ($messageId ?? '')),
            'community_id' => $communityId,
            'community_name' => $communityName !== '' ? $communityName : 'Unknown community',
            'reason' => 'member_feature',
            'channel' => $channel,
            'created_at' => now()->toIso8601String(),
        ];

        return $this->storeAndNotify($record);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{id: string, notified: bool, ref: string}
     */
    private function storeAndNotify(array $record): array
    {
        $id = (string) $record['id'];
        $ref = $this->assignRef($id);
        $record['ref'] = $ref;

        Cache::put('spike_escalation:'.$id, $record, now()->addDays(7));
        $list = Cache::get('spike_escalations', []);
        if (! is_array($list)) {
            $list = [];
        }
        array_unshift($list, $id);
        Cache::put('spike_escalations', array_slice($list, 0, 100), now()->addDays(7));

        $notified = $this->notifyAdmin($record);

        Log::info('spike.escalation.created', [
            'id' => $id,
            'ref' => $ref,
            'type' => $record['type'] ?? 'ask',
            'channel' => $record['channel'] ?? null,
            'notified' => $notified,
            'admin_contact' => config('telegram_spike.admin_contact'),
        ]);

        return ['id' => $id, 'notified' => $notified, 'ref' => $ref];
    }

    /**
     * Short admin-facing id (e.g. K2M9P7) so actions work without reply-to.
     */
    private function assignRef(string $escalationId): string
    {
        $ref = strtoupper(substr($escalationId, -6));
        $existing = Cache::get('spike_escalation_ref:'.$ref);
        if (is_string($existing) && $existing !== '' && $existing !== $escalationId) {
            $ref = strtoupper(substr($escalationId, -8));
        }

        Cache::put('spike_escalation_ref:'.$ref, $escalationId, now()->addDays(7));

        return $ref;
    }

    public function findEscalationIdByRef(string $ref): ?string
    {
        $ref = strtoupper(trim($ref));
        if ($ref === '') {
            return null;
        }

        $id = Cache::get('spike_escalation_ref:'.$ref);
        if (! is_string($id) || $id === '') {
            return null;
        }

        return $id;
    }

    /**
     * Resolve a pending escalation from a WhatsApp swipe-reply target message id.
     */
    public function findEscalationIdByWhatsAppMessageId(string $messageId): ?string
    {
        foreach ($this->whatsappMessageIdCacheKeys($messageId) as $key) {
            $id = Cache::get($key);
            if (is_string($id) && $id !== '') {
                return $id;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function whatsappMessageIdCacheKeys(string $messageId): array
    {
        $messageId = trim($messageId);
        if ($messageId === '') {
            return [];
        }

        $keys = ['spike_escalation_wa_msg:'.$messageId];
        // Bare stanza id (last segment of true_…@c.us_3EB0…)
        if (str_contains($messageId, '_')) {
            $bare = substr($messageId, strrpos($messageId, '_') + 1);
            if ($bare !== '' && $bare !== $messageId) {
                $keys[] = 'spike_escalation_wa_msg:'.$bare;
            }
        }

        return $keys;
    }

    public function rememberWhatsAppEscalationMessage(string $messageId, string $escalationId): void
    {
        $escalationId = trim($escalationId);
        if ($escalationId === '') {
            return;
        }

        foreach ($this->whatsappMessageIdCacheKeys($messageId) as $key) {
            Cache::put($key, $escalationId, now()->addDays(7));
        }
    }

    /**
     * Prefer Request ID line; fall back to `/reply REF` in the How-to-act block.
     */
    public function extractRefFromCardText(string $quoted): ?string
    {
        $quoted = trim($quoted);
        if ($quoted === '') {
            return null;
        }

        if (preg_match('/Request ID:\s*([A-Z0-9]+)/i', $quoted, $m) === 1) {
            return strtoupper(trim($m[1]));
        }

        if (preg_match('/\/reply\s+([A-Z0-9]{4,12})\b/i', $quoted, $m) === 1) {
            return strtoupper(trim($m[1]));
        }

        return null;
    }

    /**
     * Member-initiated /ask: rate-limit + blacklist aware, then escalate.
     *
     * @return array{ok: bool, reply: string}
     */
    public function handleMemberAsk(
        string $channel,
        string $from,
        string $question,
        string $communityId,
        ?string $communityName = null,
        ?string $fromName = null,
        ?string $fromPhone = null,
        ?string $chatType = null,
        ?string $chatId = null,
        ?string $messageId = null,
    ): array {
        $question = trim($question);
        if ($question === '') {
            return [
                'ok' => false,
                'reply' => "Send your question like this:\n"
                    ."/ask When is the next UniPods session?\n\n"
                    .'If I already know the answer, I\'ll reply right away. '
                    .'Otherwise an admin will see it and can reply from their side.',
            ];
        }

        if ($this->isAskBlocked($channel, $from)) {
            return [
                'ok' => false,
                'reply' => "I can't pass this along right now. "
                    .'Please ask about the community in plain language, or message an admin directly.',
            ];
        }

        if (! $this->consumeAskQuota($channel, $from)) {
            return [
                'ok' => false,
                'reply' => "You've sent a few /ask messages already. "
                    .'Please wait a bit before sending another, or ask me about the community in plain language.',
            ];
        }

        if ($communityId === '') {
            return [
                'ok' => false,
                'reply' => 'Link a community with /join first, then try /ask again.',
            ];
        }

        $result = $this->escalate([
            'question' => $question,
            'from' => $from,
            'from_name' => $fromName,
            'from_phone' => $fromPhone,
            'chat_type' => $chatType,
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'community_id' => $communityId,
            'community_name' => $communityName,
            'reason' => 'member_ask',
            'channel' => $channel,
        ]);

        if ($result['notified']) {
            return [
                'ok' => true,
                'reply' => "Got it. I've passed that to an admin.\n\n"
                    ."I'll follow up once they reply. No need to keep checking or asking again.",
            ];
        }

        return [
            'ok' => true,
            'reply' => "Got it. I've logged that for an admin.\n\n"
                ."I'll follow up once they reply. No need to keep checking or asking again "
                .'(admin notify may still be finishing setup).',
        ];
    }

    public function isAskBlocked(string $channel, string $from): bool
    {
        return (bool) Cache::get($this->askBlockKey($channel, $from), false);
    }

    public function blockAsker(string $channel, string $from): void
    {
        Cache::forever($this->askBlockKey($channel, $from), true);
        Log::info('spike.ask.blocked', ['channel' => $channel, 'from' => $from]);
    }

    public function unblockAsker(string $channel, string $from): void
    {
        Cache::forget($this->askBlockKey($channel, $from));
        Log::info('spike.ask.unblocked', ['channel' => $channel, 'from' => $from]);
    }

    /**
     * When an admin replies to an escalation card, forward that answer to the asker
     * (or blacklist them if the reply is /blacklist).
     *
     * @return array{ok: bool, reply: string}
     */
    public function forwardAdminReply(
        string $replyToTelegramMessageId,
        string $answerText,
        ?string $resolvedBy = null,
    ): array {
        $answerText = trim($answerText);
        if ($answerText === '' || $replyToTelegramMessageId === '') {
            return [
                'ok' => false,
                'reply' => "I couldn't match that to a pending question. Reply directly to the escalation card.",
            ];
        }

        $escalationId = Cache::get('spike_escalation_tg_msg:'.$replyToTelegramMessageId);
        if (! is_string($escalationId) || $escalationId === '') {
            return [
                'ok' => false,
                'reply' => "I couldn't match that to a pending question. Reply directly to the escalation card.",
            ];
        }

        $record = Cache::get('spike_escalation:'.$escalationId);
        if (! is_array($record)) {
            return [
                'ok' => false,
                'reply' => 'That escalation expired or was already cleared.',
            ];
        }

        $already = $this->alreadyResolvedReply($record);
        if ($already !== null) {
            return $already;
        }

        if (($record['type'] ?? 'ask') === 'share_review') {
            return $this->handleShareAdminDecision($escalationId, $record, $answerText, $resolvedBy);
        }

        if (($record['type'] ?? '') === 'feature_request') {
            return $this->handleFeatureAdminDecision($escalationId, $record, $answerText, $resolvedBy);
        }

        $channel = trim((string) ($record['channel'] ?? 'telegram_spike'));
        $memberChatId = trim((string) ($record['from'] ?? ''));
        $name = trim((string) ($record['from_name'] ?? ''));

        if ($this->isBlacklistCommand($answerText)) {
            if ($memberChatId === '') {
                return [
                    'ok' => false,
                    'reply' => "I couldn't tell who to block from that card.",
                ];
            }

            $this->blockAsker($channel !== '' ? $channel : 'telegram_spike', $memberChatId);
            $ref = strtoupper((string) ($record['ref'] ?? ''));

            return [
                'ok' => true,
                'reply' => 'Done. '
                    .($name !== '' ? $name : 'That member')
                    .' is blocked from further /ask messages on this channel. '
                    .($ref !== '' ? "Or later: /unblacklist {$ref}" : 'Or send /unblacklist '.$memberChatId),
            ];
        }

        if ($this->isUnblacklistCommand($answerText)) {
            if ($memberChatId === '') {
                return [
                    'ok' => false,
                    'reply' => "I couldn't tell who to unblock from that card.",
                ];
            }

            $this->unblockAsker($channel !== '' ? $channel : 'telegram_spike', $memberChatId);

            return [
                'ok' => true,
                'reply' => 'Done. '
                    .($name !== '' ? $name : 'That member')
                    .' can use /ask again.',
            ];
        }

        return $this->deliverAskAnswer($escalationId, $record, $answerText, $resolvedBy, 'telegram_spike');
    }

    /**
     * Polite no-op when an admin action hits an already-resolved request.
     *
     * @param  array<string, mixed>  $record
     * @return array{ok: bool, reply: string}|null
     */
    public function alreadyResolvedReply(array $record): ?array
    {
        $resolvedAt = trim((string) ($record['resolved_at'] ?? ''));
        if ($resolvedAt === '') {
            return null;
        }

        $ref = strtoupper(trim((string) ($record['ref'] ?? '')));
        $label = $ref !== '' ? "Request {$ref}" : 'That request';
        $by = trim((string) ($record['resolved_by'] ?? ''));
        $decision = trim((string) ($record['admin_decision'] ?? ''));

        if ($decision === 'approved') {
            $msg = "{$label} was already approved";
        } elseif ($decision === 'declined') {
            $msg = "{$label} was already declined";
        } elseif (trim((string) ($record['admin_answer'] ?? '')) !== '') {
            $msg = "{$label} was already answered";
        } else {
            $msg = "{$label} was already handled";
        }

        if ($by !== '') {
            $msg .= ' by '.$this->formatResolvedByLabel($by, $record);
        }
        $when = $this->formatResolvedAtLabel($resolvedAt);
        if ($when !== '') {
            $msg .= ' on '.$when;
        }
        $msg .= '.';

        return [
            'ok' => true,
            'reply' => $msg,
        ];
    }

    /**
     * Admin-facing actor label: prefer @phone (tappable), never raw …@lid.
     *
     * @param  array<string, mixed>  $record
     */
    private function formatResolvedByLabel(string $by, array $record): string
    {
        $phone = preg_replace('/\D+/', '', (string) ($record['resolved_by_phone'] ?? '')) ?? '';
        if ($phone !== '' && $this->looksLikeE164PhoneDigits($phone)) {
            return '@'.$phone;
        }

        $bare = trim((string) (preg_replace('/@.*/', '', $by) ?? $by));
        $digits = preg_replace('/\D+/', '', $bare) ?? '';

        if ($bare !== '') {
            $resolved = app(ChannelCommandAccess::class)->resolveWhatsAppAdminPhone($bare, [
                'from_phone' => (string) ($record['resolved_by_phone'] ?? ''),
            ]);
            if (is_string($resolved) && $resolved !== '' && $this->looksLikeE164PhoneDigits($resolved)) {
                return '@'.$resolved;
            }
        }

        // Human name / @handle — keep. Opaque LID digits — hide.
        if ($bare !== '' && ! ctype_digit($bare) && ! $this->looksLikeWhatsAppLidDigits($digits)) {
            return ltrim($bare, '~');
        }

        return 'an admin';
    }

    /**
     * Readable local time with timezone abbreviation + UTC offset (e.g. WAT (UTC+1)).
     */
    private function formatResolvedAtLabel(string $iso): string
    {
        $iso = trim($iso);
        if ($iso === '') {
            return '';
        }

        try {
            $tzName = (string) config('app.timezone', 'UTC');
            $dt = \Illuminate\Support\Carbon::parse($iso)->timezone($tzName);
            $offsetSeconds = $dt->getOffset();
            $hours = intdiv(abs($offsetSeconds), 3600);
            $mins = intdiv(abs($offsetSeconds) % 3600, 60);
            $sign = $offsetSeconds >= 0 ? '+' : '-';
            $utcLabel = $mins === 0
                ? sprintf('UTC%s%d', $sign, $hours)
                : sprintf('UTC%s%d:%02d', $sign, $hours, $mins);
            $abbr = $dt->format('T');
            $stamp = $dt->format('M j, Y \a\t g:i A');

            if (in_array(strtoupper($abbr), ['UTC', 'GMT', 'Z'], true)) {
                return "{$stamp} · {$utcLabel}";
            }

            return "{$stamp} · {$abbr} ({$utcLabel})";
        } catch (\Throwable) {
            return $iso;
        }
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{ok: bool, reply: string}
     */
    private function deliverAskAnswer(
        string $escalationId,
        array $record,
        string $answerText,
        ?string $resolvedBy = null,
        ?string $adminChannel = null,
        ?string $resolvedByPhone = null,
    ): array {
        $already = $this->alreadyResolvedReply($record);
        if ($already !== null) {
            return $already;
        }

        $answerText = trim($answerText);
        $name = trim((string) ($record['from_name'] ?? ''));
        $ref = strtoupper((string) ($record['ref'] ?? ''));
        $question = trim((string) ($record['question'] ?? ''));

        if ($answerText === '') {
            return [
                'ok' => false,
                'reply' => $ref !== ''
                    ? "Send an answer like: /reply {$ref} Your answer here"
                    : 'Send the answer text for this request.',
            ];
        }

        // First writer wins when two admins act at once (works on array cache in tests).
        if (! Cache::add('spike_resolving:'.$escalationId, 1, 15)) {
            return [
                'ok' => true,
                'reply' => $ref !== ''
                    ? "Request {$ref} is already being handled. Try again in a moment if needed."
                    : 'That request is already being handled. Try again in a moment if needed.',
            ];
        }

        $knowledgeId = null;
        try {
            $fresh = Cache::get('spike_escalation:'.$escalationId);
            if (is_array($fresh)) {
                $record = $fresh;
                $already = $this->alreadyResolvedReply($record);
                if ($already !== null) {
                    return $already;
                }
            }

            $knowledgeId = $this->publishAdminAnswerToKnowledge($record, $question, $answerText);

            $resolved = array_merge($record, [
                'resolved_at' => now()->toIso8601String(),
                'admin_answer' => $answerText,
                'knowledge_source_id' => $knowledgeId,
            ]);
            if ($resolvedBy !== null && trim($resolvedBy) !== '') {
                $resolved['resolved_by'] = trim($resolvedBy);
            }
            $phoneForRecord = trim((string) ($resolvedByPhone ?? ''));
            if ($phoneForRecord !== '') {
                $resolved['resolved_by_phone'] = preg_replace('/\D+/', '', $phoneForRecord) ?? $phoneForRecord;
            }
            Cache::put('spike_escalation:'.$escalationId, $resolved, now()->addDays(7));
        } finally {
            Cache::forget('spike_resolving:'.$escalationId);
        }

        $knowledgeNote = $knowledgeId !== null
            ? ' Saved to the knowledge base so '.app(ChannelConversationService::class)->botDisplayName().' can use it later.'
            : '';

        $channel = (string) ($record['channel'] ?? '');
        $inGroup = $this->recordOriginIsGroup($record);
        $conv = app(ChannelConversationService::class);
        $style = $conv->channelEmphasisStyle($channel);

        [$adminMentionTag, $adminMentionJids] = $this->resolveAdminWhatsAppMentions(
            $channel,
            trim((string) ($resolvedBy ?? '')),
            trim((string) ($resolvedByPhone ?? '')),
        );

        // WhatsApp: bare text — spike inserts language-neutral @mention. Telegram: display name when known.
        $memberGreetingName = null;
        if ($style === 'telegram_html' && ! $inGroup && $name !== '') {
            $memberGreetingName = $name;
        }

        $memberText = $conv->askAnsweredMemberReply(
            $question,
            $answerText,
            $memberGreetingName,
            $style,
            $adminMentionTag,
        );

        $delivered = $this->deliverAnswerToAskOrigin($record, $memberText, $adminMentionJids);
        if (! $delivered) {
            return [
                'ok' => false,
                'reply' => "I couldn't deliver that to the member"
                    .($knowledgeId !== null ? ', but the answer was saved to the knowledge base' : '')
                    .'. Please try again or message them directly.',
            ];
        }

        $where = $inGroup
            ? 'the group'
            : ($name !== '' ? $name : 'the member');

        // Admin ack is plain text on both channels — WhatsApp *bold* works; Telegram shows *REF*.
        $refBit = $ref !== '' ? ' (request *'.$ref.'*).' : '.';

        return [
            'ok' => true,
            'reply' => 'Thanks. I\'ve sent that to '.$where.$refBit.$knowledgeNote,
        ];
    }

    /**
     * Prefer a real phone @tag for admin mentions (green name). Never leave a raw
     * opaque LID in the member-facing label when we can map it to admin_phones.
     *
     * @return array{0: string|null, 1: list<string>}  [body @tag or null, mention JIDs]
     */
    private function resolveAdminWhatsAppMentions(
        string $channel,
        string $resolvedBy,
        string $resolvedByPhone,
    ): array {
        if ($channel !== 'whatsapp_web_spike') {
            return [null, []];
        }

        $access = app(ChannelCommandAccess::class);
        $phone = preg_replace('/\D+/', '', $resolvedByPhone) ?? '';
        if ($phone === '' || strlen($phone) < 10 || strlen($phone) > 13) {
            $phone = (string) ($access->resolveWhatsAppAdminPhone($resolvedBy, [
                'from_phone' => $resolvedByPhone,
            ]) ?? '');
        }
        if ($phone !== '' && (strlen($phone) < 10 || strlen($phone) > 13)) {
            $phone = '';
        }

        $jids = [];
        $tag = null;

        if ($phone !== '') {
            $tag = '@'.$phone;
            $jids[] = $phone.'@c.us';
            if ($resolvedBy !== '') {
                $access->rememberWhatsAppAdminLid($resolvedBy, $phone);
            }
        }

        // Also attach LID JID when present so LID-addressed groups can bind.
        $lidJid = $this->whatsappPeerJid($resolvedBy, '');
        if ($lidJid !== null) {
            if (! str_contains($lidJid, '@')) {
                $digits = preg_replace('/\D+/', '', $lidJid) ?? '';
                $lidJid = $this->looksLikeWhatsAppLidDigits($digits)
                    ? $digits.'@lid'
                    : ($digits !== '' ? $digits.'@c.us' : null);
            }
            if ($lidJid !== null && ! in_array($lidJid, $jids, true)) {
                $jids[] = $lidJid;
            }
        }

        // No stable phone → omit @tag (avoid ugly "@265721070268441" in the label).
        return [$tag, $jids];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function recordOriginIsGroup(array $record): bool
    {
        $chatType = strtolower(trim((string) ($record['chat_type'] ?? '')));
        if (in_array($chatType, ['group', 'supergroup'], true)) {
            return true;
        }

        $chatId = trim((string) ($record['chat_id'] ?? ''));

        return str_ends_with($chatId, '@g.us');
    }

    /**
     * Deliver the admin answer where the member asked (group thread or private DM).
     *
     * @param  array<string, mixed>  $record
     * @param  list<string>  $extraMentions  Additional WhatsApp JIDs (e.g. answering admin)
     */
    private function deliverAnswerToAskOrigin(array $record, string $text, array $extraMentions = []): bool
    {
        $channel = trim((string) ($record['channel'] ?? ''));
        $originChatId = trim((string) ($record['chat_id'] ?? ''));
        $memberChatId = trim((string) ($record['from'] ?? ''));
        $memberPhone = trim((string) ($record['from_phone'] ?? ''));
        $messageId = trim((string) ($record['message_id'] ?? ''));
        $inGroup = $this->recordOriginIsGroup($record);

        if ($channel === '' || $channel === 'telegram_spike') {
            $to = ($inGroup && $originChatId !== '') ? $originChatId : $memberChatId;
            if ($to === '') {
            return false;
        }

            return $this->notifyMember($to, $text, $messageId !== '' ? $messageId : null, 'telegram_html');
        }

        if ($channel === 'whatsapp_web_spike') {
            $mentionJid = $this->whatsappPeerJid($memberChatId, $memberPhone);
            if ($mentionJid !== null && ! str_contains($mentionJid, '@')) {
                $digits = preg_replace('/\D+/', '', $mentionJid) ?? '';
                $mentionJid = $this->looksLikeWhatsAppLidDigits($digits)
                    ? $digits.'@lid'
                    : ($digits !== '' ? $digits.'@c.us' : null);
            }

            if ($inGroup && $originChatId !== '') {
                return $this->notifyWhatsAppMember(
                    $originChatId,
                    $text,
                    $mentionJid,
                    $messageId !== '' ? $messageId : null,
                    $extraMentions,
                );
            }

            // Private: prefer the chat JID we already have (…@lid / …@c.us).
            // Using a mis-tagged LID as @c.us causes "No LID for user".
            $to = $this->whatsappPrivateOutboundTo($originChatId, $memberChatId, $memberPhone);
            if ($to === '') {
                return false;
            }

            // Private DM: quote the original ask (swipe-reply context). Skip member
            // @mention — the peer already knows the thread; tagging is for groups.
            return $this->notifyWhatsAppMember(
                $to,
                $text,
                null,
                $messageId !== '' ? $messageId : null,
                $extraMentions,
            );
        }

        Log::warning('spike.member_notify.unsupported_channel', [
            'channel' => $channel,
        ]);

        return false;
    }

    /**
     * Resolve a WhatsApp private DM peer. Never send a LID as @c.us.
     */
    private function whatsappPrivateOutboundTo(string $originChatId, string $from, string $fromPhone): string
    {
        $originChatId = trim($originChatId);
        if ($originChatId !== '' && (str_contains($originChatId, '@lid') || str_contains($originChatId, '@c.us'))) {
            return $originChatId;
        }

        $jid = $this->whatsappPeerJid($from, $fromPhone);

        return $jid ?? '';
    }

    /**
     * Build phone digits or a full …@lid / …@c.us JID for a member.
     * Returns null when nothing usable is available.
     */
    private function whatsappPeerJid(string $from, string $fromPhone): ?string
    {
        $from = trim($from);
        $fromPhone = trim($fromPhone);
        $fromDigits = preg_replace('/\D+/', '', $from) ?? '';
        $phoneDigits = preg_replace('/\D+/', '', $fromPhone) ?? '';

        // from_phone that is just the LID echoed as "number" is not a phone.
        if ($phoneDigits !== '' && $fromDigits !== '' && $phoneDigits === $fromDigits) {
            $phoneDigits = '';
        }

        if ($fromPhone !== '' && str_contains($fromPhone, '@')) {
            return $fromPhone;
        }
        if ($from !== '' && str_contains($from, '@')) {
            return $from;
        }

        if ($phoneDigits !== '' && ! $this->looksLikeWhatsAppLidDigits($phoneDigits)) {
            return $phoneDigits; // spike appends @c.us
        }

        if ($fromDigits !== '') {
            return $this->looksLikeWhatsAppLidDigits($fromDigits)
                ? $fromDigits.'@lid'
                : $fromDigits;
        }

        return null;
    }

    private function looksLikeWhatsAppLidDigits(string $digits): bool
    {
        // E.164 phones with country code are typically ≤13 digits.
        // WhatsApp LIDs are opaque and commonly 14–15+ digits (e.g. 80599524048943).
        // Treating a 14-digit LID as @c.us produces broken tags like "@+805 995…".
        return strlen($digits) >= 14;
    }

    /**
     * Persist admin Q&A so Zak can answer similar questions later.
     *
     * @param  array<string, mixed>  $record
     */
    private function publishAdminAnswerToKnowledge(array $record, string $question, string $answer): ?string
    {
        $question = app(ChannelConversationService::class)->memberFacingQuestion(trim($question));
        $answer = trim($answer);
        if ($question === '' || $answer === '') {
            return null;
        }

        $communityId = trim((string) ($record['community_id'] ?? ''));
        if ($communityId === '') {
            return null;
        }

        $community = Community::query()->find($communityId);
        if ($community === null) {
            return null;
        }

        $email = (string) config('telegram_spike.default_user_email', '');
        $actor = $email !== '' ? User::query()->where('email', $email)->first() : null;
        if ($actor === null) {
            Log::warning('spike.admin_answer.knowledge_skipped', [
                'reason' => 'no review user',
                'community_id' => $communityId,
            ]);

            return null;
        }

        $content = "Question: {$question}\n\nAnswer: {$answer}";
        $ref = strtoupper(trim((string) ($record['ref'] ?? '')));
        $name = $ref !== ''
            ? "Admin answer {$ref}"
            : 'Admin answer '.now()->format('Y-m-d H:i');

        try {
            $source = $this->lifecycle()->import($actor, [
                'tenant_id' => $community->tenant_id,
                'community_id' => $communityId,
                'name' => $name,
                'uri' => 'admin-answer://'.(string) Str::ulid(),
                'source_type' => 'markdown',
                'authority_tier' => 'official_announcement',
                'content' => $content,
                'metadata' => [
                    'channel' => (string) ($record['channel'] ?? ''),
                    'from' => (string) ($record['from'] ?? ''),
                    'escalation_ref' => $ref,
                    'origin' => 'admin_reply',
                ],
            ]);
            $this->lifecycle()->submitForReview($actor, $source);
            $published = $this->lifecycle()->publish($actor, $source->fresh() ?? $source);

            Log::info('spike.admin_answer.published', [
                'knowledge_id' => $published->id,
                'ref' => $ref,
                'community_id' => $communityId,
            ]);

            return (string) $published->id;
        } catch (\Throwable $e) {
            Log::error('spike.admin_answer.publish_failed', [
                'error' => $e->getMessage(),
                'community_id' => $communityId,
                'ref' => $ref,
            ]);

            return null;
        }
    }

    /**
     * Admin commands that do not require reply-to (useful when many cards pile up).
     *
     * /approve REF | /decline REF | /reply REF <answer> | /blacklist REF|userId | /unblacklist …
     *
     * @return array{ok: bool, reply: string}|null
     */
    public function tryAdminCommand(
        string $adminText,
        string $defaultChannel = 'telegram_spike',
        ?string $resolvedBy = null,
        ?string $resolvedByPhone = null,
    ): ?array {
        $text = trim($adminText);

        if (preg_match('/^\/?approve\s+(\S+)/iu', $text, $m) === 1) {
            return $this->runShareDecisionByRef(trim($m[1]), '/approve', $resolvedBy);
        }

        if (preg_match('/^\/?(decline|reject)\s+(\S+)/iu', $text, $m) === 1) {
            return $this->runShareDecisionByRef(trim($m[2]), '/decline', $resolvedBy);
        }

        if (preg_match('/^\/?reply\s+(\S+)\s+(.+)$/isu', $text, $m) === 1) {
            $ref = trim($m[1]);
            $answer = trim($m[2]);
            if ($this->findEscalationIdByRef($ref) === null) {
                // Valid-looking Request ID that is missing/expired vs prose mistaken for an ID.
                if (preg_match('/^[A-Z0-9]{4,12}$/i', $ref) === 1) {
                    return [
                        'ok' => false,
                        'reply' => "I couldn't find request {$ref}. It may have expired — check the Request ID on the card, then:\n"
                            ."```\n"
                            ."/reply {$ref} Your answer here\n"
                            ."```",
                    ];
                }

                // Likely forgot the Request ID: "/reply I have responded"
                return [
                    'ok' => false,
                    'reply' => "Include the Request ID from the card.\n\n"
                        ."*Example*\n"
                        ."```\n"
                        ."/reply W7X1YT Your answer here\n"
                        ."```\n\n"
                        .'Or swipe-reply to the card and type the answer (no /reply needed).',
                ];
            }

            return $this->runAskReplyByRef($ref, $answer, $resolvedBy, $defaultChannel, $resolvedByPhone);
        }

        if (preg_match('/^\/?reply\b/iu', $text) === 1) {
            return [
                'ok' => false,
                'reply' => "Include the Request ID from the card.\n\n"
                    ."*Example*\n"
                    ."```\n"
                    ."/reply W7X1YT Your answer here\n"
                    ."```\n\n"
                    .'Or swipe-reply to the card and type the answer.',
            ];
        }

        if (preg_match('/^\/?unblacklist\s+(\S+)/iu', $text, $m) === 1) {
            $target = trim($m[1]);
            $from = $this->resolveMemberFromRefOrId($target) ?? $target;
            $this->unblockAsker($defaultChannel, $from);

            return [
                'ok' => true,
                'reply' => "Done. {$from} can use /ask again.",
            ];
        }

        if (preg_match('/^\/?blacklist\s+(\S+)/iu', $text, $m) === 1) {
            $target = trim($m[1]);
            $from = $this->resolveMemberFromRefOrId($target) ?? $target;
            $this->blockAsker($defaultChannel, $from);

            return [
                'ok' => true,
                'reply' => "Done. {$from} is blocked from further /ask messages.",
            ];
        }

        return null;
    }

    /**
     * @deprecated Prefer tryAdminCommand
     *
     * @return array{ok: bool, reply: string}|null
     */
    public function tryAdminBlacklistCommand(string $adminText, string $defaultChannel = 'telegram_spike'): ?array
    {
        return $this->tryAdminCommand($adminText, $defaultChannel);
    }

    /**
     * @return array{ok: bool, reply: string}
     */
    private function runShareDecisionByRef(string $ref, string $decision, ?string $resolvedBy = null): array
    {
        $escalationId = $this->findEscalationIdByRef($ref);
        if ($escalationId === null) {
            return [
                'ok' => false,
                'reply' => "I couldn't find request {$ref}. Check the Request ID on the card.",
            ];
        }

        $record = Cache::get('spike_escalation:'.$escalationId);
        if (! is_array($record)) {
            return [
                'ok' => false,
                'reply' => "Request {$ref} expired or was already cleared.",
            ];
        }

        $already = $this->alreadyResolvedReply($record);
        if ($already !== null) {
            return $already;
        }

        if (($record['type'] ?? '') === 'feature_request') {
            return $this->handleFeatureAdminDecision($escalationId, $record, $decision, $resolvedBy);
        }

        if (($record['type'] ?? '') !== 'share_review') {
            return [
                'ok' => false,
                'reply' => "Request {$ref} is not a share or feature review. Use /reply {$ref} <answer> for member questions.",
            ];
        }

        return $this->handleShareAdminDecision($escalationId, $record, $decision, $resolvedBy);
    }

    /**
     * @return array{ok: bool, reply: string}
     */
    private function runAskReplyByRef(
        string $ref,
        string $answer,
        ?string $resolvedBy = null,
        ?string $adminChannel = null,
        ?string $resolvedByPhone = null,
    ): array {
        $escalationId = $this->findEscalationIdByRef($ref);
        if ($escalationId === null) {
            return [
                'ok' => false,
                'reply' => "I couldn't find request {$ref}. Check the Request ID on the card.",
            ];
        }

        $record = Cache::get('spike_escalation:'.$escalationId);
        if (! is_array($record)) {
            return [
                'ok' => false,
                'reply' => "Request {$ref} expired or was already cleared.",
            ];
        }

        $already = $this->alreadyResolvedReply($record);
        if ($already !== null) {
            return $already;
        }

        if (($record['type'] ?? 'ask') === 'share_review') {
            return [
                'ok' => false,
                'reply' => "Request {$ref} is a share review. Use /approve {$ref} or /decline {$ref}.",
            ];
        }

        if (($record['type'] ?? '') === 'feature_request') {
            return [
                'ok' => false,
                'reply' => "Request {$ref} is a feature request. Use /approve {$ref} or /decline {$ref}.",
            ];
        }

        return $this->deliverAskAnswer(
            $escalationId,
            $record,
            $answer,
            $resolvedBy,
            $adminChannel,
            $resolvedByPhone,
        );
    }

    private function resolveMemberFromRefOrId(string $target): ?string
    {
        $escalationId = $this->findEscalationIdByRef($target);
        if ($escalationId === null) {
            return null;
        }

        $record = Cache::get('spike_escalation:'.$escalationId);
        if (! is_array($record)) {
            return null;
        }

        $from = trim((string) ($record['from'] ?? ''));

        return $from !== '' ? $from : null;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function notifyAdmin(array $record): bool
    {
        $telegramOk = $this->notifyTelegramAdmin($record);
        $whatsappOk = $this->notifyWhatsAppAdmins($record);

        if (! $telegramOk && ! $whatsappOk) {
            Log::warning('spike.escalation.notify_skipped', [
                'reason' => 'no Telegram admin chat and no WhatsApp admin DM delivered',
                'escalation_id' => $record['id'] ?? null,
                'channel' => $record['channel'] ?? null,
            ]);
        }

        return $telegramOk || $whatsappOk;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function notifyTelegramAdmin(array $record): bool
    {
        $token = (string) config('telegram_spike.bot_token', '');
        $chatId = (string) config('telegram_spike.admin_chat_id', '');

        if ($token === '' || $chatId === '') {
            return false;
        }

        $text = $this->formatAdminMessage($record, 'plain');
        $conversation = app(ChannelConversationService::class);
        $text = $conversation->escapeTelegramHtml($text);
        $text = $conversation->formatCommandsInText($text, 'telegram_html');

        $response = Http::timeout(15)->asJson()->post(
            "https://api.telegram.org/bot{$token}/sendMessage",
            [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]
        );

        if ($response->failed()) {
            Log::error('spike.escalation.telegram_notify_failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'escalation_id' => $record['id'] ?? null,
            ]);

            return false;
        }

        $telegramMessageId = (string) ($response->json('result.message_id') ?? '');
        if ($telegramMessageId !== '') {
            Cache::put(
                'spike_escalation_tg_msg:'.$telegramMessageId,
                (string) $record['id'],
                now()->addDays(7)
            );
        }

        return true;
    }

    /**
     * DM configured WhatsApp admin phones via the web-spike outbound HTTP bridge.
     *
     * @param  array<string, mixed>  $record
     */
    private function notifyWhatsAppAdmins(array $record): bool
    {
        $base = rtrim((string) config('whatsapp_web_spike.outbound_url', ''), '/');
        $secret = (string) config('whatsapp_web_spike.shared_secret', '');
        if ($base === '' || $secret === '') {
            return false;
        }

        $botDigits = preg_replace('/\D+/', '', (string) config('whatsapp_web_spike.bot_number', '')) ?? '';
        $phones = [];
        $raw = config('whatsapp_web_spike.admin_phones', []);
        if (is_array($raw)) {
            foreach ($raw as $phone) {
                $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
                if ($digits === '' || ($botDigits !== '' && $digits === $botDigits)) {
                    continue; // never DM the linked bot account itself
                }
                $phones[] = $digits;
            }
        }
        $phones = array_values(array_unique($phones));
        if ($phones === []) {
            return false;
        }

        $text = $this->formatAdminMessage($record, 'whatsapp');
        $text = app(ChannelConversationService::class)->formatCommandsInText($text, 'whatsapp');
        $mentionJid = null;
        // Only mention WhatsApp peers — Telegram chat ids are not WA contacts.
        if (trim((string) ($record['channel'] ?? '')) !== 'telegram_spike') {
            $mentionJid = $this->whatsappPeerJid(
                trim((string) ($record['from'] ?? '')),
                trim((string) ($record['from_phone'] ?? '')),
            );
            if ($mentionJid !== null && ! str_contains($mentionJid, '@')) {
                $mentionJid = $this->looksLikeWhatsAppLidDigits(preg_replace('/\D+/', '', $mentionJid) ?? '')
                    ? $mentionJid.'@lid'
                    : $mentionJid.'@c.us';
            }
        }
        // Every @digits in the card (Name, Cc:, Request body) needs a mentions[]
        // JID or WhatsApp leaves them as plain numbers (not green).
        $mentionJids = $this->whatsappMentionJidsFromText($text);
        if ($mentionJid !== null && $mentionJid !== '' && ! in_array($mentionJid, $mentionJids, true)) {
            array_unshift($mentionJids, $mentionJid);
        }
        $any = false;

        foreach ($phones as $phone) {
            try {
                $payload = [
                    'secret' => $secret,
                    'to' => $phone,
                    'text' => $text,
                ];
                if ($mentionJid !== null && $mentionJid !== '') {
                    $payload['mention'] = $mentionJid;
                }
                if ($mentionJids !== []) {
                    $payload['mentions'] = $mentionJids;
                }
                $response = Http::timeout(20)->asJson()->post($base.'/send', $payload);
            } catch (\Throwable $e) {
                Log::error('spike.escalation.whatsapp_notify_exception', [
                    'to' => $phone,
                    'error' => $e->getMessage(),
                    'escalation_id' => $record['id'] ?? null,
                ]);

                continue;
            }

            if ($response->failed()) {
                Log::error('spike.escalation.whatsapp_notify_failed', [
                    'to' => $phone,
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'escalation_id' => $record['id'] ?? null,
                ]);

                continue;
            }

            $any = true;
            // Seed phone so a later LID inbound can be linked when from_phone arrives.
            Cache::put('whatsapp_admin_phone_seen:'.$phone, true, now()->addDays(30));

            $waMessageId = trim((string) ($response->json('message_id') ?? ''));
            if ($waMessageId !== '' && is_string($record['id'] ?? null) && $record['id'] !== '') {
                $this->rememberWhatsAppEscalationMessage($waMessageId, (string) $record['id']);
            }
        }

        return $any;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  'plain'|'whatsapp'  $style
     */
    private function formatAdminMessage(array $record, string $style = 'plain'): string
    {
        $nameLine = $this->adminCardNameLine($record, $style);

        $id = trim((string) ($record['from'] ?? ''));
        if ($id === '') {
            $id = 'Unknown';
        } else {
            // Show bare digits in the card; keep @lid/@c.us only for delivery.
            $id = preg_replace('/@.*/', '', $id) ?? $id;
        }

        $community = trim((string) ($record['community_name'] ?? ''));
        if ($community === '') {
            $community = 'Unknown community';
        }

        $question = trim((string) ($record['question'] ?? ''));
        if ($question === '') {
            $question = '(No question text)';
        } else {
            // Prefer the clean follow-up / ask; keep prior context for admin when present.
            $facing = app(ChannelConversationService::class)->memberFacingQuestion($question);
            if ($facing !== '' && $facing !== $question) {
                $prior = '';
                if (preg_match('/Original question:\s*(.+?)(?:\n\n|$)/us', $question, $om) === 1) {
                    $prior = trim($om[1]);
                }
                $question = $facing;
                if ($prior !== '' && $prior !== $facing) {
                    $question .= "\n\n(Prior question: {$prior})";
                }
            }
        }

        $channel = trim((string) ($record['channel'] ?? ''));
        $channelLabel = $this->channelDisplayName($channel);
        $channelLine = $channelLabel !== '' ? "Channel: {$channelLabel}\n\n" : '';
        $ref = strtoupper(trim((string) ($record['ref'] ?? '')));
        $refLine = $ref !== '' ? "Request ID: {$ref}\n\n" : '';

        $why = $this->friendlyReason((string) ($record['reason'] ?? ''), $style);
        $wa = $style === 'whatsapp';

        if (($record['type'] ?? '') === 'share_review'
            || str_contains(strtolower((string) ($record['reason'] ?? '')), 'member_share')) {
            $note = trim((string) ($record['content'] ?? $record['question'] ?? ''));
            if ($note === '') {
                $note = '(No note text)';
            }

            if ($wa && $ref !== '') {
                $actions = "*How to act:*\n"
                    ."1. Swipe-reply to this card with:\n"
                    ."```\n"
                    ."/approve\n"
                    ."/decline\n"
                    ."```\n"
                    ."2. Or send with the Request ID:\n"
                    ."```\n"
                    ."/approve {$ref}\n"
                    ."/decline {$ref}\n"
                    ."```";
            } elseif ($ref !== '') {
                $actions = '/approve '.$ref.' - publish so '.app(ChannelConversationService::class)->botDisplayName()." can use it\n/decline {$ref} - reject it\n\n"
                    .'(You can also reply to this card with /approve or /decline.)';
            } else {
                $bot = app(ChannelConversationService::class)->botDisplayName();
                $actions = "Reply /approve to publish it so {$bot} can use it in answers.\n"
                    .'Reply /decline to reject it.';
            }

            $title = $wa
                ? "*Member shared a note.*\n\n"
                : "Member shared a note for the knowledge base.\n\n";

            return $title
                .$refLine
                .$nameLine."\n\n"
                ."Member ID: {$id}\n\n"
                .$channelLine
                ."Community: {$community}\n\n"
                ."Note:\n{$note}\n\n"
                .$actions;
        }

        if (($record['type'] ?? '') === 'feature_request'
            || str_contains(strtolower((string) ($record['reason'] ?? '')), 'member_feature')) {
            $note = trim((string) ($record['content'] ?? $record['question'] ?? ''));
            if ($note === '') {
                $note = '(No request text)';
            }

            if ($wa && $ref !== '') {
                $actions = "*How to act:*\n"
                    ."1. Swipe-reply to this card with:\n"
                    ."```\n"
                    ."/approve\n"
                    ."/decline\n"
                    ."```\n"
                    ."2. Or send with the Request ID:\n"
                    ."```\n"
                    ."/approve {$ref}\n"
                    ."/decline {$ref}\n"
                    ."```";
            } elseif ($ref !== '') {
                $actions = "/approve {$ref} - note as approved and notify the member\n"
                    ."/decline {$ref} - decline and notify the member\n\n"
                    .'(You can also reply to this card with /approve or /decline.)';
            } else {
                $actions = "Reply /approve to accept this feature request and notify the member.\n"
                    .'Reply /decline to decline it and notify the member.';
            }

            $title = $wa
                ? "*Member requested a feature.*\n\n"
                : "Member requested a new feature or an improvement to an existing one.\n\n";

            return $title
                .$refLine
                .$nameLine."\n\n"
                ."Member ID: {$id}\n\n"
                .$channelLine
                ."Community: {$community}\n\n"
                ."Request:\n{$note}\n\n"
                .$actions;
        }

        if ($wa && $ref !== '') {
            $actions = "*How to act:*\n"
                ."1. Swipe-reply to this card with:\n"
                ."   • the answer (plain text - detected automatically)\n"
                ."   • or ```/blacklist```\n"
                ."2. Or send:\n"
                ."```\n"
                ."/reply {$ref} Your answer here\n"
                ."/blacklist {$ref}\n"
                ."```";
        } elseif ($ref !== '') {
            $actions = "/reply {$ref} Your answer here\n/blacklist {$ref} - stop /ask spam from this member\n\n"
                .'(Or reply to this message with just the answer text.)';
        } else {
            $actions = "Reply to this message with the answer, and I'll send it when I can.\n"
                .'Or reply with /blacklist to stop this member from further /ask spam.';
        }

        $bot = app(ChannelConversationService::class)->botDisplayName();
        $title = $wa
            ? "*{$bot} needs a quick hand.*\n\n"
            : "{$bot} needs a quick hand.\n\n";

        return $title
            .$refLine
            .$nameLine."\n\n"
            ."Member ID: {$id}\n\n"
            .$channelLine
            ."Community: {$community}\n\n"
            ."Question:\n{$question}\n\n"
            ."Why:\n{$why}\n\n"
            .$actions;
    }

    /**
     * WhatsApp Name line on admin cards: prefer a green-capable @mention (phone,
     * then LID) so admins can tap/track the member. Full display names are for
     * knowledge answers about people — not for forwarded admin cards.
     * Never @-tag Telegram user ids as fake WhatsApp phones.
     *
     * @param  array<string, mixed>  $record
     * @param  'plain'|'whatsapp'  $style
     */
    private function adminCardNameLine(array $record, string $style): string
    {
        $name = trim((string) ($record['from_name'] ?? ''));
        $name = ltrim($name, '~');
        $from = trim((string) ($record['from'] ?? ''));
        $fromDigits = preg_replace('/\D+/', '', preg_replace('/@.*/', '', $from) ?? $from) ?? '';
        $phoneDigits = preg_replace('/\D+/', '', (string) ($record['from_phone'] ?? '')) ?? '';

        // LID echoed into from_phone is not a real MSISDN.
        if (
            $phoneDigits !== ''
            && $this->looksLikeWhatsAppLidDigits($phoneDigits)
            && ! $this->looksLikeE164PhoneDigits($phoneDigits)
        ) {
            if ($fromDigits === '') {
                $fromDigits = $phoneDigits;
            }
            $phoneDigits = '';
        }

        $channel = trim((string) ($record['channel'] ?? ''));
        $telegramMember = $channel === 'telegram_spike'
            || ($phoneDigits === '' && $fromDigits !== '' && ! $this->looksLikeWhatsAppLidDigits($fromDigits)
                && ! $this->looksLikeE164PhoneDigits($fromDigits));

        if ($style === 'whatsapp') {
            if (! $telegramMember) {
                $tag = '';
                if ($phoneDigits !== '' && $this->looksLikeE164PhoneDigits($phoneDigits)) {
                    $tag = $phoneDigits;
                } elseif ($fromDigits !== '' && $this->looksLikeE164PhoneDigits($fromDigits)) {
                    $tag = $fromDigits;
                } elseif ($fromDigits !== '' && $this->looksLikeWhatsAppLidDigits($fromDigits)) {
                    $tag = $fromDigits;
                } elseif ($phoneDigits !== '' && (
                    $this->looksLikeE164PhoneDigits($phoneDigits)
                    || $this->looksLikeWhatsAppLidDigits($phoneDigits)
                )) {
                    $tag = $phoneDigits;
                }
                if ($tag !== '') {
                    return 'Name: @'.$tag;
                }
            }
            if ($name !== '') {
                return 'Name: '.$name;
            }
            if ($fromDigits !== '') {
                return $telegramMember
                    ? 'Name: Telegram user '.$fromDigits
                    : 'Name: '.$fromDigits;
            }

            return 'Name: Unknown';
        }

        if ($name !== '') {
            return 'Name: '.$name;
        }
        if ($fromDigits !== '') {
            return 'Name: '.$fromDigits;
        }

        return 'Name: Unknown';
    }

    /**
     * Human label for admin cards (never raw adapter keys like telegram_spike).
     */
    private function channelDisplayName(string $channel): string
    {
        return match (trim($channel)) {
            'telegram_spike' => 'Telegram',
            'whatsapp_web_spike', 'whatsapp_zavu' => 'WhatsApp',
            '' => '',
            default => trim($channel),
        };
    }

    /**
     * Collect @digit tags from admin-card / outbound text → WhatsApp JIDs.
     *
     * @return list<string>
     */
    private function whatsappMentionJidsFromText(string $text): array
    {
        $jids = [];
        if (preg_match_all('/@(\d{6,})\b/u', $text, $matches) < 1) {
            return [];
        }
        foreach ($matches[1] as $digits) {
            $digits = preg_replace('/\D+/', '', (string) $digits) ?? '';
            if ($digits === '') {
                continue;
            }
            $jid = $this->looksLikeWhatsAppLidDigits($digits)
                ? $digits.'@lid'
                : $digits.'@c.us';
            if (! in_array($jid, $jids, true)) {
                $jids[] = $jid;
            }
        }

        return $jids;
    }

    private function looksLikeE164PhoneDigits(string $digits): bool
    {
        $len = strlen($digits);

        // E.164 without +: country code + NSN, typically 10–13 digits.
        return $len >= 10 && $len <= 13;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{ok: bool, reply: string}
     */
    private function handleShareAdminDecision(
        string $escalationId,
        array $record,
        string $answerText,
        ?string $resolvedBy = null,
    ): array {
        $already = $this->alreadyResolvedReply($record);
        if ($already !== null) {
            return $already;
        }

        $decision = mb_strtolower(trim($answerText));
        $decision = rtrim($decision, " \t\n\r.!？?");
        $knowledgeId = trim((string) ($record['knowledge_source_id'] ?? ''));
        $name = trim((string) ($record['from_name'] ?? ''));
        $memberChatId = trim((string) ($record['from'] ?? ''));
        $memberPhone = trim((string) ($record['from_phone'] ?? ''));
        $channel = trim((string) ($record['channel'] ?? ''));
        $notifyTo = $memberPhone !== '' ? $memberPhone : $memberChatId;

        if (! in_array($decision, ['/approve', 'approve', '/decline', 'decline', '/reject', 'reject'], true)) {
            $ref = strtoupper((string) ($record['ref'] ?? ''));

            return [
                'ok' => false,
                'reply' => $ref !== ''
                    ? "For shared notes, use /approve {$ref} or /decline {$ref} (or reply to the card with /approve)."
                    : 'For shared notes, reply with /approve to publish or /decline to reject.',
            ];
        }

        if ($knowledgeId === '') {
            return [
                'ok' => false,
                'reply' => "I couldn't find the shared note for this card.",
            ];
        }

        $source = KnowledgeSource::query()->find($knowledgeId);
        if ($source === null) {
            return [
                'ok' => false,
                'reply' => 'That shared note was removed or already cleared.',
            ];
        }

        $actor = User::query()->find($source->created_by);
        if ($actor === null) {
            $email = (string) config('telegram_spike.default_user_email', '');
            $actor = $email !== '' ? User::query()->where('email', $email)->first() : null;
        }
        if ($actor === null) {
            return [
                'ok' => false,
                'reply' => "I couldn't publish that right now (no review user configured).",
            ];
        }

        $approve = in_array($decision, ['/approve', 'approve'], true);

        if (! Cache::add('spike_resolving:'.$escalationId, 1, 15)) {
            $ref = strtoupper((string) ($record['ref'] ?? ''));

            return [
                'ok' => true,
                'reply' => $ref !== ''
                    ? "Request {$ref} is already being handled. Try again in a moment if needed."
                    : 'That request is already being handled. Try again in a moment if needed.',
            ];
        }

        try {
            $fresh = Cache::get('spike_escalation:'.$escalationId);
            if (is_array($fresh)) {
                $record = $fresh;
                $already = $this->alreadyResolvedReply($record);
                if ($already !== null) {
                    return $already;
                }
            }

            try {
                if ($approve) {
                    $this->lifecycle()->submitForReview($actor, $source);
                    $this->lifecycle()->publish($actor, $source->fresh() ?? $source);
                } else {
                    $this->lifecycle()->reject($actor, $source, 'Declined by admin via Telegram');
                }
            } catch (\Throwable $e) {
                Log::error('spike.share_review.failed', [
                    'knowledge_id' => $knowledgeId,
                    'decision' => $decision,
                    'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                    'reply' => "I couldn't ".($approve ? 'publish' : 'reject').' that note: '.$e->getMessage(),
            ];
        }

            $resolved = array_merge($record, [
            'resolved_at' => now()->toIso8601String(),
                'admin_decision' => $approve ? 'approved' : 'declined',
            ]);
            if ($resolvedBy !== null && trim($resolvedBy) !== '') {
                $resolved['resolved_by'] = trim($resolvedBy);
            }
            Cache::put('spike_escalation:'.$escalationId, $resolved, now()->addDays(7));
        } finally {
            Cache::forget('spike_resolving:'.$escalationId);
        }

        $note = trim((string) ($record['content'] ?? $record['question'] ?? ''));
        $conv = app(ChannelConversationService::class);
        $style = $conv->channelEmphasisStyle($channel);
        $memberText = $approve
            ? $conv->shareApprovedMemberReply(
                $name !== '' ? $name : null,
                $note !== '' ? $note : null,
                $style,
            )
            : $conv->shareDeclinedMemberReply(
                $name !== '' ? $name : null,
                $note !== '' ? $note : null,
                $style,
            );

        if ($notifyTo !== '') {
            $this->notifyMemberOnChannel($channel, $notifyTo, $memberText, $style);
        }

        if ($approve) {
        return [
            'ok' => true,
                'reply' => 'Approved. That note is published. '
                    .app(ChannelConversationService::class)->botDisplayName().' can use it in answers now.',
            ];
        }

        return [
            'ok' => true,
            'reply' => 'Declined. That note will not be added to the knowledge base.',
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{ok: bool, reply: string}
     */
    private function handleFeatureAdminDecision(
        string $escalationId,
        array $record,
        string $answerText,
        ?string $resolvedBy = null,
    ): array {
        $already = $this->alreadyResolvedReply($record);
        if ($already !== null) {
            return $already;
        }

        $decision = mb_strtolower(trim($answerText));
        $decision = rtrim($decision, " \t\n\r.!？?");
        $name = trim((string) ($record['from_name'] ?? ''));
        $channel = trim((string) ($record['channel'] ?? ''));

        if (! in_array($decision, ['/approve', 'approve', '/decline', 'decline', '/reject', 'reject'], true)) {
            $ref = strtoupper((string) ($record['ref'] ?? ''));

            return [
                'ok' => false,
                'reply' => $ref !== ''
                    ? "For feature requests, use /approve {$ref} or /decline {$ref} (or reply to the card with /approve)."
                    : 'For feature requests, reply with /approve to accept or /decline to reject.',
            ];
        }

        $approve = in_array($decision, ['/approve', 'approve'], true);

        if (! Cache::add('spike_resolving:'.$escalationId, 1, 15)) {
            $ref = strtoupper((string) ($record['ref'] ?? ''));

            return [
                'ok' => true,
                'reply' => $ref !== ''
                    ? "Request {$ref} is already being handled. Try again in a moment if needed."
                    : 'That request is already being handled. Try again in a moment if needed.',
            ];
        }

        try {
            $fresh = Cache::get('spike_escalation:'.$escalationId);
            if (is_array($fresh)) {
                $record = $fresh;
                $already = $this->alreadyResolvedReply($record);
                if ($already !== null) {
                    return $already;
                }
            }

            $resolved = array_merge($record, [
                'resolved_at' => now()->toIso8601String(),
                'admin_decision' => $approve ? 'approved' : 'declined',
            ]);
            if ($resolvedBy !== null && trim($resolvedBy) !== '') {
                $resolved['resolved_by'] = trim($resolvedBy);
            }
            Cache::put('spike_escalation:'.$escalationId, $resolved, now()->addDays(7));

            // Keep a rolling log of decided feature requests (not knowledge).
            $log = Cache::get('spike_feature_decisions', []);
            if (! is_array($log)) {
                $log = [];
            }
            array_unshift($log, [
                'id' => $escalationId,
                'ref' => $resolved['ref'] ?? null,
                'decision' => $approve ? 'approved' : 'declined',
                'content' => $record['content'] ?? $record['question'] ?? '',
                'from' => $record['from'] ?? null,
                'community_id' => $record['community_id'] ?? null,
                'resolved_at' => $resolved['resolved_at'],
            ]);
            Cache::put('spike_feature_decisions', array_slice($log, 0, 200), now()->addDays(30));
        } finally {
            Cache::forget('spike_resolving:'.$escalationId);
        }

        $note = trim((string) ($record['content'] ?? $record['question'] ?? ''));
        $conv = app(ChannelConversationService::class);
        $style = $conv->channelEmphasisStyle($channel);
        $inGroup = $this->recordOriginIsGroup($record);

        $memberGreetingName = null;
        if ($style === 'telegram_html' && ! $inGroup && $name !== '') {
            $memberGreetingName = $name;
        }

        $memberText = $approve
            ? $conv->featureApprovedMemberReply(
                $memberGreetingName,
                $note !== '' ? $note : null,
                $style,
            )
            : $conv->featureDeclinedMemberReply(
                $memberGreetingName,
                $note !== '' ? $note : null,
                $style,
            );

        // WhatsApp group replies: lead with @mention so the asker is tagged in-channel.
        if ($style === 'whatsapp' && $inGroup) {
            $tagDigits = preg_replace('/\D+/', '', (string) ($record['from_phone'] ?? '')) ?? '';
            $fromDigits = preg_replace('/\D+/', '', (string) ($record['from'] ?? '')) ?? '';
            if ($tagDigits === '' || strlen($tagDigits) > 13) {
                $tagDigits = (strlen($fromDigits) >= 10 && strlen($fromDigits) <= 13) ? $fromDigits : '';
            }
            if ($tagDigits !== '') {
                $memberText = '@'.$tagDigits.",\n\n".ltrim(preg_replace('/^(?:Hi\s+)?@[^\n]*\n\n/u', '', $memberText) ?? $memberText);
                $memberText = ltrim(preg_replace('/^Hi[^\n]*\n\n/u', '', $memberText) ?? $memberText);
            }
        }

        $delivered = $this->deliverAnswerToAskOrigin($record, $memberText);
        // Prefer @mention of the member who raised it (never "the group").
        $who = $this->memberAckLabel($record, $style);

        if (! $delivered) {
            return [
                'ok' => false,
                'reply' => 'I noted the decision, but could not notify '.$who
                    .'. Please message them directly.',
            ];
        }

        if ($approve) {
            return [
                'ok' => true,
                'reply' => 'Approved. Noted, and I notified '.$who.'.',
            ];
        }

        return [
            'ok' => true,
            'reply' => 'Declined. Noted, and I notified '.$who.'.',
        ];
    }

    /**
     * Admin-facing label for the member who raised a request (a reference, not
     * an identity line). Prefer a green-capable WhatsApp @tag (phone, then LID).
     * Telegram / plain: @handle when present, else display name.
     *
     * @param  array<string, mixed>  $record
     * @param  'whatsapp'|'telegram_html'  $style
     */
    private function memberAckLabel(array $record, string $style = 'whatsapp'): string
    {
        $name = trim((string) ($record['from_name'] ?? ''));
        $name = ltrim($name, '~');
        $from = trim((string) ($record['from'] ?? ''));
        $fromDigits = preg_replace('/\D+/', '', preg_replace('/@.*/', '', $from) ?? $from) ?? '';
        $phoneDigits = preg_replace('/\D+/', '', (string) ($record['from_phone'] ?? '')) ?? '';

        // LID echoed into from_phone is not a real MSISDN — keep it as the LID id.
        if (
            $phoneDigits !== ''
            && $this->looksLikeWhatsAppLidDigits($phoneDigits)
            && ! $this->looksLikeE164PhoneDigits($phoneDigits)
        ) {
            if ($fromDigits === '') {
                $fromDigits = $phoneDigits;
            }
            $phoneDigits = '';
        }

        if ($style === 'whatsapp') {
            $tag = '';
            if ($phoneDigits !== '' && $this->looksLikeE164PhoneDigits($phoneDigits)) {
                $tag = $phoneDigits;
            } elseif ($fromDigits !== '' && $this->looksLikeE164PhoneDigits($fromDigits)) {
                $tag = $fromDigits;
            } elseif ($fromDigits !== '' && $this->looksLikeWhatsAppLidDigits($fromDigits)) {
                $tag = $fromDigits;
            } elseif ($phoneDigits !== '' && (
                $this->looksLikeE164PhoneDigits($phoneDigits)
                || $this->looksLikeWhatsAppLidDigits($phoneDigits)
            )) {
                $tag = $phoneDigits;
            }
            if ($tag !== '') {
                return '@'.$tag;
            }
        }

        if ($name !== '' && str_starts_with($name, '@')) {
            return $name;
        }
        if ($name !== '') {
            return $name;
        }
        if ($fromDigits !== '') {
            return $style === 'whatsapp' ? '@'.$fromDigits : $fromDigits;
        }

        return 'the member';
    }

    /**
     * Notify the member who shared/asked (Telegram bot API or WhatsApp spike outbound).
     *
     * @param  'whatsapp'|'telegram_html'|null  $style
     */
    private function notifyMemberOnChannel(
        string $channel,
        string $chatId,
        string $text,
        ?string $style = null,
    ): bool {
        // Older cached cards may omit channel; Telegram was the original path.
        if ($channel === '' || $channel === 'telegram_spike') {
            return $this->notifyMember($chatId, $text, null, $style ?? 'telegram_html');
        }

        if ($channel === 'whatsapp_web_spike') {
            // Prefer phone; fall back to LID as …@lid so the spike can DM the same peer.
            $to = $chatId;
            if (! str_contains($to, '@') && strlen(preg_replace('/\D+/', '', $to) ?? '') > 15) {
                $to = $to.'@lid';
            }

            return $this->notifyWhatsAppMember($to, $text);
        }

        // Unknown channel adapter: treat as undelivered so the admin can follow up.
        Log::warning('spike.member_notify.unsupported_channel', [
            'channel' => $channel,
            'chat_id' => $chatId,
        ]);

        return false;
    }

    /**
     * @param  list<string>  $extraMentions
     */
    private function notifyWhatsAppMember(
        string $to,
        string $text,
        ?string $mentionJid = null,
        ?string $quotedMessageId = null,
        array $extraMentions = [],
    ): bool {
        $base = rtrim((string) config('whatsapp_web_spike.outbound_url', ''), '/');
        $secret = (string) config('whatsapp_web_spike.shared_secret', '');
        if ($base === '' || $secret === '' || trim($to) === '') {
            return false;
        }

        $payload = [
            'secret' => $secret,
            'to' => $to,
            'text' => $text,
        ];
        if ($mentionJid !== null && trim($mentionJid) !== '') {
            $payload['mention'] = trim($mentionJid);
        }
        $mentions = [];
        if ($mentionJid !== null && trim($mentionJid) !== '') {
            $mentions[] = trim($mentionJid);
        }
        foreach ($extraMentions as $extra) {
            $extra = trim((string) $extra);
            if ($extra !== '' && ! in_array($extra, $mentions, true)) {
                $mentions[] = $extra;
            }
        }
        if ($mentions !== []) {
            $payload['mentions'] = $mentions;
        }
        if ($quotedMessageId !== null && trim($quotedMessageId) !== '') {
            $payload['quoted_message_id'] = trim($quotedMessageId);
        }

        try {
            $response = Http::timeout(20)->asJson()->post($base.'/send', $payload);
        } catch (\Throwable $e) {
            Log::warning('spike.share_review.whatsapp_member_notify_exception', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if ($response->failed()) {
            Log::warning('spike.share_review.whatsapp_member_notify_failed', [
                'to' => $to,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    private function notifyMember(
        string $chatId,
        string $text,
        ?string $replyToMessageId = null,
        ?string $style = null,
    ): bool {
        $token = (string) config('telegram_spike.bot_token', '');
        if ($token === '' || $chatId === '') {
            return false;
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
        ];
        if ($style === 'telegram_html' || str_contains($text, '<b>')) {
            $payload['parse_mode'] = 'HTML';
        }
        if ($replyToMessageId !== null && trim($replyToMessageId) !== '') {
            $payload['reply_to_message_id'] = (int) $replyToMessageId;
        }

        $response = Http::timeout(15)->asJson()->post(
            "https://api.telegram.org/bot{$token}/sendMessage",
            $payload
        );

        if ($response->failed()) {
            Log::warning('spike.share_review.member_notify_failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    private function friendlyReason(string $reason, string $style = 'plain'): string
    {
        $lower = strtolower($reason);
        $conversation = app(ChannelConversationService::class);
        $cmdStyle = $style === 'whatsapp' ? 'whatsapp' : ($style === 'telegram_html' ? 'telegram_html' : 'plain');

        if (str_contains($lower, 'member_share')) {
            return 'The member used '.$conversation->highlightCommand('/share', $cmdStyle)
                .' so an admin can approve it for the knowledge base.';
        }

        if (str_contains($lower, 'member_feature')) {
            return 'The member used '.$conversation->highlightCommand('/feature', $cmdStyle)
                .' so an admin can approve or decline the suggestion.';
        }

        if (str_contains($lower, 'member_ask')) {
            return 'The member used '.$conversation->highlightCommand('/ask', $cmdStyle)
                .' so an admin can see this and reply.';
        }

        if (str_contains($lower, 'insufficient')
            || str_contains($lower, 'did not contain enough')
            || str_contains($lower, 'not contain enough')) {
            return "I couldn't find enough in what we have shared to answer confidently.";
        }

        if (str_contains($lower, 'confidence') || str_contains($lower, 'threshold')) {
            return 'Nothing solid enough turned up in what we have shared.';
        }

        if (str_contains($lower, 'conflict')) {
            return 'What we have shared seems to disagree, so a human should decide.';
        }

        if (str_contains($lower, 'hallucinat') || str_contains($lower, 'unanchored')) {
            return "I wasn't able to ground an answer safely in what we have shared.";
        }

        if (trim($reason) === '') {
            return "I couldn't answer this one from what we have shared.";
        }

        return "I couldn't answer this one from what we have shared.";
    }

    private function askBlockKey(string $channel, string $from): string
    {
        return 'spike_ask_block:'.sha1($channel.'|'.$from);
    }

    private function askRateKey(string $channel, string $from): string
    {
        return 'spike_ask_rate:'.sha1($channel.'|'.$from);
    }

    private function consumeAskQuota(string $channel, string $from): bool
    {
        $key = $this->askRateKey($channel, $from);
        $count = (int) Cache::get($key, 0);
        if ($count >= self::ASK_RATE_MAX) {
            return false;
        }

        if ($count === 0) {
            Cache::put($key, 1, now()->addHours(self::ASK_RATE_HOURS));
        } else {
            Cache::increment($key);
        }

        return true;
    }

    private function isBlacklistCommand(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        $t = rtrim($t, " \t\n\r.!？?");

        return $t === '/blacklist' || $t === 'blacklist';
    }

    private function isUnblacklistCommand(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        $t = rtrim($t, " \t\n\r.!？?");

        return $t === '/unblacklist' || $t === 'unblacklist';
    }
}
