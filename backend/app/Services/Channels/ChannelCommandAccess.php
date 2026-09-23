<?php

declare(strict_types=1);

namespace App\Services\Channels;

use Illuminate\Support\Facades\Cache;

/**
 * Member vs admin command access. Admins configured via env (multi-value).
 */
final class ChannelCommandAccess
{
    public function isAdmin(string $channel, string $senderId, array $raw = []): bool
    {
        $senderId = trim($senderId);
        if ($senderId === '') {
            return false;
        }

        if ($channel === 'whatsapp_web_spike') {
            $phones = $this->whatsappAdminPhones();
            if ($this->matchesAnyPhone($senderId, $phones)) {
                return true;
            }

            $fromPhone = trim((string) ($raw['from_phone'] ?? ''));
            if ($fromPhone !== '' && $this->matchesAnyPhone($fromPhone, $phones)) {
                $this->rememberWhatsAppAdminLid($senderId, $fromPhone);

                return true;
            }

            if ($this->isRememberedWhatsAppAdminLid($senderId)) {
                return true;
            }

            // Spike convenience: private admin action + LID sender
            // (WhatsApp often delivers DMs as @lid without contact.number).
            // Covers /reply commands AND swipe-replies to escalation cards (plain answers).
            $chatType = strtolower(trim((string) ($raw['chat_type'] ?? 'private')));
            $isPrivate = $chatType === '' || $chatType === 'private' || $chatType === 'dm';
            $cardReply = $this->looksLikeEscalationCardReply($raw);
            $text = (string) ($raw['text'] ?? '');
            $helpOrStart = (bool) preg_match('/^\/?(help|start)\b/iu', ltrim($text));
            $adminAction = $this->isAdminOnlyCommand($text) || $cardReply || $helpOrStart;
            if ($isPrivate && $adminAction) {
                $linked = $this->tryLinkSoleWhatsAppAdminLid($senderId, $phones);
                if ($linked) {
                    return true;
                }

                // Fallback: exactly one admin phone was recently DMed an escalation card.
                $linked = $this->tryLinkRecentlyNotifiedWhatsAppAdminLid($senderId, $phones);
                if ($linked) {
                    return true;
                }

                // Multi-admin: swipe-reply to a card in private proves they received it.
                if ($cardReply) {
                    $candidates = $this->nonBotAdminPhoneDigits($phones);
                    if ($candidates !== []) {
                        $this->rememberWhatsAppAdminLid($senderId, $candidates[0]);

                        return true;
                    }
                }
            }

            return false;
        }

        if ($channel === 'telegram_spike') {
            $adminChatId = trim((string) config('telegram_spike.admin_chat_id', ''));

            return $adminChatId !== '' && $senderId === $adminChatId;
        }

        return false;
    }

    public function isAdminOnlyCommand(string $text): bool
    {
        $trimmed = ltrim($text);
        if ($trimmed === '') {
            return false;
        }

        if (preg_match('/^\/?([a-z]+)\b/iu', $trimmed, $m) !== 1) {
            return false;
        }

        $cmd = strtolower($m[1]);

        return in_array($cmd, ChannelListenGate::ADMIN_COMMANDS, true);
    }

    /**
     * Swipe-reply to a Zak escalation/share card (private DM). Used so LID-only
     * admins can answer without typing /reply REF …
     *
     * @param  array<string, mixed>  $raw
     */
    public function looksLikeEscalationCardReply(array $raw): bool
    {
        if (! filter_var($raw['reply_to_bot'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        $quotedMsgId = trim((string) ($raw['quoted_message_id'] ?? ''));
        if ($quotedMsgId !== '') {
            $escId = app(SpikeEscalationNotifier::class)->findEscalationIdByWhatsAppMessageId($quotedMsgId);
            if ($escId !== null) {
                return true;
            }
        }

        $quoted = trim((string) ($raw['quoted_text'] ?? ''));
        if ($quoted === '') {
            return false;
        }

        $lower = mb_strtolower($quoted);
        $bot = mb_strtolower(app(ChannelConversationService::class)->botDisplayName());

        // Title-only WhatsApp quote previews often omit "Request ID:" — still a card.
        if (str_contains($lower, $bot.' needs a quick hand')
            || str_contains($lower, 'zak needs a quick hand') // legacy cards
            || str_contains($lower, 'member shared a note')
            || str_contains($lower, 'member requested a feature')) {
            return true;
        }

        if (preg_match('/Request ID:\s*[A-Z0-9]+/i', $quoted) !== 1) {
            return false;
        }

        return str_contains($lower, 'how to act')
            || str_contains($lower, '/approve')
            || str_contains($lower, '/reply');
    }

    /**
     * When exactly one non-bot admin phone is configured, bind this LID to it.
     *
     * @param  list<string>  $phones
     */
    private function tryLinkSoleWhatsAppAdminLid(string $senderId, array $phones): bool
    {
        $senderDigits = $this->normalizeDigits($senderId);
        // Only bind unknown LIDs (long ids). Never promote a different phone to admin.
        if (strlen($senderDigits) < 15) {
            return false;
        }

        $candidates = $this->nonBotAdminPhoneDigits($phones);
        if (count($candidates) !== 1) {
            return false;
        }

        $this->rememberWhatsAppAdminLid($senderId, $candidates[0]);

        return true;
    }

    /**
     * When multiple admins exist, bind LID if exactly one was recently sent a card.
     *
     * @param  list<string>  $phones
     */
    private function tryLinkRecentlyNotifiedWhatsAppAdminLid(string $senderId, array $phones): bool
    {
        $senderDigits = $this->normalizeDigits($senderId);
        if (strlen($senderDigits) < 15) {
            return false;
        }

        $seen = [];
        foreach ($this->nonBotAdminPhoneDigits($phones) as $digits) {
            if (Cache::get('whatsapp_admin_phone_seen:'.$digits)) {
                $seen[] = $digits;
            }
        }
        $seen = array_values(array_unique($seen));
        if (count($seen) !== 1) {
            return false;
        }

        $this->rememberWhatsAppAdminLid($senderId, $seen[0]);

        return true;
    }

    /**
     * @param  list<string>  $phones
     * @return list<string>
     */
    private function nonBotAdminPhoneDigits(array $phones): array
    {
        $botDigits = preg_replace('/\D+/', '', (string) config('whatsapp_web_spike.bot_number', '')) ?? '';
        $candidates = [];
        foreach ($phones as $phone) {
            $digits = $this->normalizeDigits($phone);
            if ($digits === '' || ($botDigits !== '' && $digits === $botDigits)) {
                continue;
            }
            $candidates[] = $digits;
        }

        return array_values(array_unique($candidates));
    }

    public function adminOnlyDenial(string $style = 'plain'): string
    {
        if ($style === 'whatsapp') {
            return "*That command is admin-only.*\n\n"
                ."Try:\n"
                ."```\n"
                ."/ask your question\n"
                ."/share something the community should know\n"
                ."```\n"
                .'Or ask an admin for help.';
        }

        return 'That command is admin-only. Try /ask or /share, or ask an admin for help.';
    }

    /**
     * @return list<string>
     */
    public function whatsappAdminPhones(): array
    {
        $raw = config('whatsapp_web_spike.admin_phones', []);
        if (is_string($raw)) {
            return self::parsePhoneList($raw);
        }
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $phone) {
            $phone = trim((string) $phone);
            if ($phone !== '') {
                $out[] = $phone;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>  $admins
     */
    public function matchesAnyPhone(string $sender, array $admins): bool
    {
        $senderDigits = $this->normalizeDigits($sender);
        if ($senderDigits === '') {
            return false;
        }

        foreach ($admins as $admin) {
            $adminDigits = $this->normalizeDigits($admin);
            if ($adminDigits === '') {
                continue;
            }
            if ($this->phonesEqual($senderDigits, $adminDigits)) {
                return true;
            }
        }

        return false;
    }

    /**
     * When an admin DMs via LID, remember it so later commands still match.
     */
    public function rememberWhatsAppAdminLid(string $lidOrId, string $phone): void
    {
        $lid = $this->normalizeDigits($lidOrId);
        $phoneDigits = $this->normalizeDigits($phone);
        if ($lid === '' || $phoneDigits === '') {
            return;
        }

        Cache::put('whatsapp_admin_lid:'.$lid, $phoneDigits, now()->addDays(30));
    }

    public function isRememberedWhatsAppAdminLid(string $lidOrId): bool
    {
        return $this->phoneForWhatsAppAdminLid($lidOrId) !== null;
    }

    /**
     * Phone digits previously linked to this admin LID, if any.
     */
    public function phoneForWhatsAppAdminLid(string $lidOrId): ?string
    {
        $lid = $this->normalizeDigits($lidOrId);
        if ($lid === '') {
            return null;
        }

        $phone = Cache::get('whatsapp_admin_lid:'.$lid);
        if (! is_string($phone) || $phone === '') {
            return null;
        }

        $phoneDigits = $this->normalizeDigits($phone);
        if ($phoneDigits === '' || ! $this->matchesAnyPhone($phoneDigits, $this->whatsappAdminPhones())) {
            return null;
        }

        return $phoneDigits;
    }

    /**
     * Stable MSISDN for an admin sender (prefer phone over opaque LID).
     * Used so member-facing @mentions paint a green contact name.
     *
     * @param  array<string, mixed>  $raw
     */
    public function resolveWhatsAppAdminPhone(string $senderId, array $raw = []): ?string
    {
        $senderDigits = $this->normalizeDigits($senderId);
        $fromPhone = $this->normalizeDigits((string) ($raw['from_phone'] ?? ''));

        // Inbound contact.number when it is a real phone (not the LID echoed back).
        if ($fromPhone !== ''
            && strlen($fromPhone) >= 10
            && strlen($fromPhone) <= 13
            && ($senderDigits === '' || $fromPhone !== $senderDigits)) {
            if ($senderDigits !== '') {
                $this->rememberWhatsAppAdminLid($senderId, $fromPhone);
            }

            return $fromPhone;
        }

        $cached = $this->phoneForWhatsAppAdminLid($senderId);
        if ($cached !== null) {
            return $cached;
        }

        // Sender id is already a configured admin phone.
        $admins = $this->nonBotAdminPhoneDigits($this->whatsappAdminPhones());
        foreach ($admins as $admin) {
            if ($senderDigits !== '' && $this->phonesEqual($senderDigits, $admin)) {
                return $admin;
            }
        }

        // Single-admin deploy: LID DMs map to that phone for stable mentions.
        if (count($admins) === 1 && strlen($senderDigits) >= 14) {
            $this->rememberWhatsAppAdminLid($senderId, $admins[0]);

            return $admins[0];
        }

        return null;
    }

    /**
     * Compare national vs international forms (e.g. 0811… vs 234811…).
     */
    private function phonesEqual(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        $aCore = ltrim($a, '0');
        $bCore = ltrim($b, '0');
        if ($aCore !== '' && $aCore === $bCore) {
            return true;
        }

        $minLen = min(strlen($aCore), strlen($bCore));
        if ($minLen >= 9
            && (str_ends_with($aCore, $bCore) || str_ends_with($bCore, $aCore))) {
            return true;
        }

        return false;
    }

    public function normalizeDigits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /**
     * @return list<string>
     */
    public static function parsePhoneList(?string $csv): array
    {
        if ($csv === null || trim($csv) === '') {
            return [];
        }

        $out = [];
        foreach (explode(',', $csv) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return array_values(array_unique($out));
    }
}
