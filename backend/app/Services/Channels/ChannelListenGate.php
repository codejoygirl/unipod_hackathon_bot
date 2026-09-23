<?php

declare(strict_types=1);

namespace App\Services\Channels;

/**
 * Channel listen rules for WhatsApp spike:
 * - Private / DM: always listen (no @ needed).
 * - Groups: only when @mentioned / alias, swipe-reply to bot (caller flag),
 *   or a recognized /command.
 * Telegram stays always-listen (callers simply skip this gate).
 */
final class ChannelListenGate
{
    /** @var list<string> */
    public const MEMBER_COMMANDS = ['join', 'help', 'start', 'ask', 'share', 'feature'];

    /** @var list<string> */
    public const ADMIN_COMMANDS = [
        'import',
        'export', // alias of /import (legacy)
        'asset', // register a Drive/program file into knowledge
        'publish', // publish a knowledge draft from chat
        'knowledge', // list drafts / published / assets
        'kb', // alias of /knowledge
        'features', // list open / decided feature requests
        'approve',
        'decline',
        'reject',
        'reply',
        'blacklist',
        'unblacklist',
        'logins',
        'loginas',
    ];

    /**
     * @param  list<string>  $aliases
     */
    public function shouldListen(
        string $chatType,
        string $text,
        array $aliases,
        string $mode = 'mention_or_command',
        bool $fromAdmin = false,
    ): bool {
        $chatType = strtolower(trim($chatType));
        $isPrivate = $chatType === '' || $chatType === 'private' || $chatType === 'dm';

        if ($mode === 'off') {
            return false;
        }

        if ($mode === 'private_only' && ! $isPrivate) {
            return false;
        }

        // 1:1 chats: always respond. Groups stay mention/command-gated.
        if ($isPrivate) {
            return true;
        }

        if ($mode !== 'mention_or_command' && $mode !== 'private_only') {
            return false;
        }

        // Configured admins: always listen in groups so durable updates can be
        // model-gated into knowledge (and questions still get intelligent replies).
        if ($fromAdmin) {
            return true;
        }

        // Swipe-reply-to-bot is handled by the adapter via bot_mentioned / reply_to_bot.
        if ($this->containsAlias($text, $aliases)) {
            return true;
        }

        return $this->startsWithRecognizedCommand($text);
    }

    /**
     * Strip the first matching bot alias / @mention from the message body.
     *
     * @param  list<string>  $aliases
     */
    public function stripMentions(string $text, array $aliases): string
    {
        $trimmed = trim($text);
        if ($trimmed === '' || $aliases === []) {
            return $trimmed;
        }

        $patterns = [];
        foreach ($aliases as $alias) {
            $alias = trim((string) $alias);
            if ($alias === '') {
                continue;
            }

            if (str_starts_with($alias, '@')) {
                $handle = preg_quote(ltrim($alias, '@'), '/');
                // Real mention: require leading @
                $patterns[] = '/^@'.$handle.'(?!\d)[,:]?\s*/iu';
                $patterns[] = '/(?:^|[\s])@'.$handle.'(?!\d)[,:]?\s*/iu';
                continue;
            }

            $escaped = preg_quote($alias, '/');
            // Digit LIDs often glue to the next word: "@1006…Who" — (?!\d) not \b.
            $patterns[] = '/^@?'.$escaped.'(?!\d)[,:]?\s*/iu';
            $patterns[] = '/@?'.$escaped.'(?!\d)[,:]?\s*/iu';
        }

        foreach ($patterns as $pattern) {
            $next = preg_replace($pattern, ' ', $trimmed, 1);
            if (is_string($next) && trim($next) !== $trimmed) {
                return trim(preg_replace('/\s+/', ' ', $next) ?? $next);
            }
        }

        return $trimmed;
    }

    /**
     * @param  list<string>  $aliases
     */
    public function containsAlias(string $text, array $aliases): bool
    {
        $haystack = mb_strtolower($text);
        $haystackDigits = preg_replace('/\D+/', '', $text) ?? '';

        foreach ($aliases as $alias) {
            $alias = trim((string) $alias);
            if ($alias === '') {
                continue;
            }
            $needle = mb_strtolower($alias);

            // Real @username mention — require the @ (e.g. @unipod_bot)
            if (str_starts_with($needle, '@')) {
                $handle = substr($needle, 1);
                if ($handle !== ''
                    && preg_match('/(^|[^\w])@'.preg_quote($handle, '/').'\b/u', $haystack) === 1) {
                    return true;
                }

                continue;
            }

            // Phone-style aliases: match ignoring spaces / dashes / +
            $aliasDigits = preg_replace('/\D+/', '', $alias) ?? '';
            if (strlen($aliasDigits) >= 9 && $haystackDigits !== '') {
                $core = ltrim($aliasDigits, '0');
                if ($core !== '' && str_contains($haystackDigits, $core)) {
                    return true;
                }
                if (str_contains($haystackDigits, $aliasDigits)) {
                    return true;
                }

                continue;
            }

            // Text aliases (e.g. zak_bot) — allow optional @
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
            if ($needle !== '' && str_contains($haystack, '@'.$needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build mention tokens for a phone (intl + national + +prefix).
     *
     * @return list<string>
     */
    public static function phoneMentionVariants(string $phone): array
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return [];
        }

        $out = [$digits, '+'.$digits];
        $core = ltrim($digits, '0');
        if ($core !== '' && $core !== $digits) {
            $out[] = $core;
            $out[] = '0'.$core;
        }

        // Nigeria: 234XXXXXXXXXX ↔ 0XXXXXXXXXX
        if (str_starts_with($digits, '234') && strlen($digits) >= 12) {
            $national = '0'.substr($digits, 3);
            $out[] = $national;
            $out[] = substr($digits, 3);
            $out[] = '+234'.substr($digits, 3);
        }

        return array_values(array_unique(array_filter($out)));
    }

    public function startsWithRecognizedCommand(string $text): bool
    {
        $trimmed = ltrim($text);
        if ($trimmed === '') {
            return false;
        }

        // JOIN-token form
        if (preg_match('/^\/?join-/iu', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/^\/?([a-z]+)\b/iu', $trimmed, $m) !== 1) {
            return false;
        }

        $cmd = strtolower($m[1]);

        return in_array($cmd, [...self::MEMBER_COMMANDS, ...self::ADMIN_COMMANDS], true);
    }

    /**
     * @return list<string>
     */
    public static function parseAliasList(?string $csv): array
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
