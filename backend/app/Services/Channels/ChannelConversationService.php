<?php

declare(strict_types=1);

namespace App\Services\Channels;

use Illuminate\Support\Facades\Cache;

/**
 * Lightweight per-user chat memory + routing for channel spikes.
 * Conversational turns stay local; knowledge turns may include recent context.
 */
final class ChannelConversationService
{
    public const INTENT_CONVERSATIONAL = 'conversational';

    public const INTENT_KNOWLEDGE = 'knowledge';

    public const INTENT_OUT_OF_SCOPE = 'out_of_scope';

    /** Growth / study / motivate — answer in DM; redirect from groups. */
    public const INTENT_PERSONAL_HELP = 'personal_help';

    private const MAX_TURNS = 12;

    private const SESSION_TTL_HOURS = 24;

    /** Member/admin-facing bot name (ZAK_BOT_DISPLAY_NAME). */
    public function botDisplayName(): string
    {
        $name = trim((string) config('zak_presence.display_name', 'Zak Bot'));

        return $name !== '' ? $name : 'Zak Bot';
    }

    public function sessionKey(string $channel, string $externalUserId, ?string $threadKey = null): string
    {
        $user = trim($externalUserId);
        $thread = trim((string) ($threadKey ?? ''));
        // Per-user always; optional thread (chat/group id) keeps group contexts isolated.
        $scope = $thread !== ''
            ? $channel.'|'.$thread.'|'.$user
            : $channel.'|'.$user;

        return $channel.'_session:'.sha1($scope);
    }

    /**
     * @return list<array{role: string, text: string}>
     */
    public function turns(string $channel, string $externalUserId, ?string $threadKey = null): array
    {
        $payload = Cache::get($this->sessionKey($channel, $externalUserId, $threadKey));
        if (! is_array($payload)) {
            return [];
        }

        $turns = $payload['turns'] ?? [];

        return is_array($turns) ? array_values($turns) : [];
    }

    public function remember(
        string $channel,
        string $externalUserId,
        string $role,
        string $text,
        ?string $threadKey = null,
    ): void {
        $key = $this->sessionKey($channel, $externalUserId, $threadKey);
        $turns = $this->turns($channel, $externalUserId, $threadKey);
        $turns[] = [
            'role' => $role,
            'text' => mb_substr(trim($text), 0, 500),
        ];
        if (count($turns) > self::MAX_TURNS) {
            $turns = array_slice($turns, -self::MAX_TURNS);
        }

        Cache::put($key, ['turns' => $turns], now()->addHours(self::SESSION_TTL_HOURS));
    }

    /**
     * True when routing should ask the model (hybrid cascade).
     * Deterministic only for empty/social and hard out-of-scope safety cases.
     */
    public function needsModelRouting(string $text): bool
    {
        $text = $this->normalizeMemberQuery($text);
        // Already-built follow-up envelopes must not be re-classified.
        if (str_starts_with(trim($text), 'The member is following up')) {
            return false;
        }
        // English-only offline follow-up heuristic (model preferred when available).
        if ($this->isContextualFollowUpOffline($text)) {
            return false;
        }

        $normalized = mb_strtolower(trim($text));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $stripped = rtrim($normalized, " \t\n\r\0\x0B.!？?~");

        if ($stripped === '' || $this->isPurelySocial($stripped) || $this->isClearlyOutOfScope($stripped)) {
            return false;
        }

        return true;
    }

    public function classifyIntent(string $text, ?string $communityDescription = null): string
    {
        $text = $this->normalizeMemberQuery($text);
        $normalized = mb_strtolower(trim($text));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $stripped = rtrim($normalized, " \t\n\r\0\x0B.!？?~");

        if ($stripped === '') {
            return self::INTENT_CONVERSATIONAL;
        }

        if ($this->isPurelySocial($stripped)) {
            return self::INTENT_CONVERSATIONAL;
        }

        if ($this->isClearlyOutOfScope($stripped)) {
            return self::INTENT_OUT_OF_SCOPE;
        }

        if ($this->looksLikeCommunityKnowledgeAsk($stripped, $communityDescription)) {
            return self::INTENT_KNOWLEDGE;
        }

        // Offline fallback when the model is unavailable: person-ish asks still search.
        if ($this->looksLikePersonLookup($stripped)) {
            return self::INTENT_KNOWLEDGE;
        }

        // Non-ASCII / accented text: prefer search over a hard refuse (model decides online).
        if ($this->looksLikeNonEnglishCommunityAsk($stripped)) {
            return self::INTENT_KNOWLEDGE;
        }

        // Offline default when the model is down: search community notes rather than
        // a hard refuse. The model owns true out_of_scope when available.
        return self::INTENT_KNOWLEDGE;
    }

    public function clarificationReply(): string
    {
        return "Hmm, I didn't catch a clear question there 🙂 "
            .'What do you need from the community — a person, session, link, or deadline? '
            ."Once I know, I'll look it up.";
    }

    /**
     * Identity blurb for the model: display name + programme coverage.
     * Lets the LLM treat UniPods/hackathon as this community without hard overrides.
     *
     * @return array{name: string, scope: string}
     */
    public function communityModelContext(?string $communityName, ?string $communityDescription): array
    {
        $name = trim((string) $communityName);
        if ($name === '') {
            $name = 'this community';
        }

        $coverage = trim((string) $communityDescription);
        if ($coverage === '') {
            return [
                'name' => $name,
                'scope' => "Display name: {$name}. "
                    .'Help with schedules, sessions, updates, links, and what has been shared here.',
            ];
        }

        return [
            'name' => $name,
            'scope' => "Display name: {$name}. "
                ."This community's programme / coverage: {$coverage}. "
                .'Members may use programme names from those notes (for example UniPods, Wadhwani, METI, hackathon) '
                ."when talking about {$name}; those topics are in-scope for this community.",
        ];
    }

    /**
     * Light cleanup so "whonis" / "Who dianee" still route to knowledge search.
     */
    public function normalizeMemberQuery(string $text): string
    {
        $t = trim($text);
        $t = str_replace(["\u{2019}", "\u{2018}", '`'], "'", $t);
        $t = preg_replace('/\bwhonis\b/iu', 'who is', $t) ?? $t;
        $t = preg_replace('/\bwhois\b/iu', 'who is', $t) ?? $t;
        $t = preg_replace('/\bwhos\b/iu', "who's", $t) ?? $t;
        $t = preg_replace('/\bwhats\b/iu', "what's", $t) ?? $t;
        $t = preg_replace('/\bwheres\b/iu', "where's", $t) ?? $t;
        // "@~Joy❤️" / "@Joy" style tags left in the body without resolved ids
        // (do not strip @digits — those are WA LIDs / phones).
        $t = preg_replace('/@~(?!\d)([^\s@]{2,60})/u', '$1', $t) ?? $t;
        $t = preg_replace('/@(?!\d)([\p{L}][\p{L}\p{N}_.-]{1,59})/u', '$1', $t) ?? $t;
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;

        return trim($t);
    }

    /**
     * Replace WhatsApp @id mentions with resolved display names so retrieval
     * can find "Joy" instead of searching for a LID digit string.
     *
     * @param  list<array{id?: string, name?: string|null, phone?: string|null}>  $mentions
     * @param  list<string>  $botIds  Bot LID / phone digits to skip
     */
    public function expandMentionedPeople(string $text, array $mentions, array $botIds = []): string
    {
        $out = trim($text);
        if ($out === '' || $mentions === []) {
            return $out;
        }

        $botSet = [];
        foreach ($botIds as $id) {
            $digits = preg_replace('/\D+/', '', (string) $id) ?? '';
            if ($digits !== '') {
                $botSet[$digits] = true;
            }
        }

        foreach ($mentions as $mention) {
            if (! is_array($mention)) {
                continue;
            }
            $id = preg_replace('/\D+/', '', (string) ($mention['id'] ?? '')) ?? '';
            if ($id === '') {
                continue;
            }

            // Drop bot self-mentions from the ask body entirely.
            // Use (?!\d) not \b — WA often glues "@1006…Who" with no boundary between digits and letters.
            if (isset($botSet[$id])) {
                $out = preg_replace('/@'.$id.'(?!\d)/u', ' ', $out) ?? $out;

                continue;
            }

            $name = trim((string) ($mention['name'] ?? ''));
            $name = ltrim($name, '~');
            // Drop emoji-only fluff from the search label but keep letters.
            $searchName = trim((string) (preg_replace('/[^\p{L}\p{N}\s._-]+/u', '', $name) ?? $name));
            $searchName = trim(preg_replace('/\s+/u', ' ', $searchName) ?? $searchName);
            if ($searchName === '') {
                continue;
            }

            $out = preg_replace('/@'.$id.'(?!\d)/u', $searchName, $out) ?? $out;
            // Visible "@~Joy❤️" form when the client left the pushname in the body
            $quotedName = preg_quote($name, '/');
            if ($quotedName !== '') {
                $out = preg_replace('/@~?'.$quotedName.'/u', $searchName, $out) ?? $out;
            }
            $quotedSearch = preg_quote($searchName, '/');
            if ($quotedSearch !== '' && $quotedSearch !== $quotedName) {
                $out = preg_replace('/@~?'.$quotedSearch.'\S*/u', $searchName, $out) ?? $out;
            }
        }

        // Also strip any leftover bot digit tags from the body.
        foreach (array_keys($botSet) as $botId) {
            $out = preg_replace('/@'.$botId.'(?!\d)/u', ' ', $out) ?? $out;
        }

        $out = trim(preg_replace('/\s+/u', ' ', $out) ?? $out);

        return $this->normalizeMemberQuery($out);
    }

    /**
     * Prefer a green-capable WhatsApp @tag when referencing a group member.
     * Prefer the resolved full display name when the ask is about who they are.
     * Never invent tags; only reuse ids/phones from the inbound mention roster.
     *
     * @param  list<array{id?: string, name?: string|null, phone?: string|null}>  $mentions
     * @param  list<string>  $botIds
     */
    public function applyGroupPeopleMentions(
        string $answer,
        array $mentions,
        bool $preferFullName = false,
        array $botIds = [],
    ): string {
        $out = trim($answer);
        if ($out === '' || $mentions === []) {
            return $out;
        }

        $botSet = [];
        foreach ($botIds as $id) {
            $digits = preg_replace('/\D+/', '', (string) $id) ?? '';
            if ($digits !== '') {
                $botSet[$digits] = true;
            }
        }

        $rows = [];
        foreach ($mentions as $mention) {
            if (! is_array($mention)) {
                continue;
            }
            $id = preg_replace('/\D+/', '', (string) ($mention['id'] ?? '')) ?? '';
            if ($id === '' || isset($botSet[$id])) {
                continue;
            }
            $phone = preg_replace('/\D+/', '', (string) ($mention['phone'] ?? '')) ?? '';
            if ($phone !== '' && (strlen($phone) < 10 || strlen($phone) > 13)) {
                $phone = '';
            }
            // Prefer E.164 phone tags for green paint; fall back to LID/user id.
            $tag = $phone !== '' ? $phone : $id;

            $name = trim((string) ($mention['name'] ?? ''));
            $name = ltrim($name, '~');
            $searchName = trim((string) (preg_replace('/[^\p{L}\p{N}\s._-]+/u', '', $name) ?? $name));
            $searchName = trim(preg_replace('/\s+/u', ' ', $searchName) ?? $searchName);
            if ($searchName === '' || $tag === '') {
                continue;
            }
            $rows[] = [
                'tag' => $tag,
                'name' => $searchName,
                'id' => $id,
                'phone' => $phone,
            ];
        }

        if ($rows === []) {
            return $out;
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => mb_strlen($b['name']) <=> mb_strlen($a['name'])
        );

        if ($preferFullName) {
            foreach ($rows as $row) {
                foreach (array_unique(array_filter([$row['tag'], $row['id'], $row['phone']])) as $digits) {
                    $out = preg_replace('/@'.preg_quote((string) $digits, '/').'(?!\d)/u', $row['name'], $out) ?? $out;
                }
            }

            return trim($out);
        }

        foreach ($rows as $row) {
            $quoted = preg_quote($row['name'], '/');
            if ($quoted === '') {
                continue;
            }
            // Whole-name reference → green @tag (skip if already tagged).
            $out = preg_replace(
                '/(?<!@)\b'.$quoted.'\b/ui',
                '@'.$row['tag'],
                $out
            ) ?? $out;
            // Prefer phone tag over a bare LID tag when both appear.
            if ($row['phone'] !== '' && $row['id'] !== '' && $row['phone'] !== $row['id']) {
                $out = preg_replace(
                    '/@'.preg_quote($row['id'], '/').'(?!\d)/u',
                    '@'.$row['phone'],
                    $out
                ) ?? $out;
            }
        }

        return trim($out);
    }

    /**
     * Prefer green-capable @phone tags over opaque LIDs when the inbound mention
     * roster includes a phone. Leaves unknown @ids untouched (never invents tags).
     *
     * @param  list<array{id?: string, name?: string|null, phone?: string|null}>  $mentions
     */
    public function preferGreenMentionTags(string $text, array $mentions): string
    {
        $out = $text;
        if ($out === '' || $mentions === []) {
            return $out;
        }

        foreach ($mentions as $mention) {
            if (! is_array($mention)) {
                continue;
            }
            $id = preg_replace('/\D+/', '', (string) ($mention['id'] ?? '')) ?? '';
            $phone = preg_replace('/\D+/', '', (string) ($mention['phone'] ?? '')) ?? '';
            if ($id === '' || $phone === '' || strlen($phone) < 10 || strlen($phone) > 13) {
                continue;
            }
            if ($id === $phone) {
                continue;
            }
            $out = preg_replace('/@'.preg_quote($id, '/').'(?!\d)/u', '@'.$phone, $out) ?? $out;
        }

        return $out;
    }

    /**
     * Person-ish asks used only as offline fallback when model classify is down.
     */
    public function looksLikePersonLookup(string $text): bool
    {
        $q = mb_strtolower(trim($text));
        $q = str_replace(["\u{2019}", "\u{2018}", '`'], "'", $q);
        $q = rtrim($q, " \t\n\r\0\x0B.!？?~");

        if ($q === '' || preg_match('/^who\s+won\b/u', $q) === 1) {
            return false;
        }

        // Allow unicode / emoji-ish display names after mention expansion ("who's joy").
        return preg_match('/^who(?:\'?s|\s+is|\s+are)?\s+[\p{L}\p{N}_][\p{L}\p{N}\s._-]{0,40}$/u', $q) === 1
            || preg_match('/^who(?:\'?s|\s+is|\s+are)?\s+[\p{L}\p{N}_]{2,40}\b/u', $q) === 1
            || preg_match('/^(?:tell me about|what about)\s+[\p{L}\p{N}_]{2,40}\b/u', $q) === 1;
    }

    /**
     * Resolve what to do with this turn, using recent chat when the member says
     * "try again" / "answer again" instead of repeating the question.
     *
     * @param  list<array{role: string, text: string}>  $turns
     * @return array{intent: string, query: string}
     */
    public function resolveInbound(
        string $text,
        array $turns = [],
        ?string $communityDescription = null,
    ): array {
        $trimmed = $this->normalizeMemberQuery($text);

        if ($this->isRetryRequest($trimmed)) {
            $prior = $this->lastRetrievableUserQuestion($turns);
            if ($prior !== null) {
                return [
                    'intent' => self::INTENT_KNOWLEDGE,
                    'query' => $prior,
                ];
            }

            return [
                'intent' => self::INTENT_CONVERSATIONAL,
                'query' => $trimmed,
            ];
        }

        // Offline English follow-up fallback when the model is down.
        // Multilingual follow-ups are handled by classify (follow_up=yes).
        if ($this->isContextualFollowUpOffline($trimmed)) {
            $prior = $this->lastRetrievableUserQuestion($turns);
            if ($prior !== null) {
                if ($this->isNearDuplicateAsk($trimmed, $prior)) {
                    return [
                        'intent' => self::INTENT_KNOWLEDGE,
                        'query' => $trimmed,
                        'link_mode' => $this->inferLinkMode($trimmed),
                    ];
                }

                return [
                    'intent' => self::INTENT_KNOWLEDGE,
                    'query' => $this->buildFollowUpKnowledgeQuery(
                        $trimmed,
                        $prior,
                        $this->lastKnowledgeAssistantAnswer($turns),
                    ),
                ];
            }
        }

        // Just "@zak" / "zak_bot": continue prior ask if any, otherwise friendly chat.
        if ($this->isBareBotPing($trimmed)) {
            $prior = $this->lastRetrievableUserQuestion($turns);
            if ($prior !== null) {
                return [
                    'intent' => self::INTENT_KNOWLEDGE,
                    'query' => $prior,
                ];
            }

            return [
                'intent' => self::INTENT_CONVERSATIONAL,
                'query' => $trimmed,
            ];
        }

        return [
            'intent' => $this->classifyIntent($trimmed, $communityDescription),
            'query' => $trimmed,
        ];
    }

    /**
     * True when the member only pinged Zak (no real ask in the message body).
     *
     * @param  list<string>  $aliases
     */
    public function isBareBotPing(string $text, array $aliases = []): bool
    {
        $raw = trim($text);
        if ($raw === '') {
            return true;
        }

        $merged = array_values(array_unique(array_filter(array_merge(
            ['zak', 'zak_bot'],
            $aliases,
        ))));
        $body = $this->stripBotAddressing($raw, $merged);
        if ($body === '') {
            return true;
        }

        // WhatsApp sometimes leaves only the bot's LID/phone after expand.
        return preg_match('/^@?\d{6,}$/u', $body) === 1;
    }

    /**
     * Friendly reply when someone just types @zak to start chatting.
     *
     * @param  'plain'|'whatsapp'  $style
     * @param  'whatsapp'|'telegram'|'web'|null  $currentChannel
     * @param  'private'|'group'|null  $chatType
     */
    public function mentionPingReply(
        string $style = 'plain',
        ?string $currentChannel = null,
        ?string $chatType = null,
        ?string $memberPhoneForWeb = null,
    ): string {
        $intro = $this->shortIntro($style, $currentChannel, $chatType, $memberPhoneForWeb);

        return "Hey — I'm here 🙂\n\n{$intro}\n\n"
            .'Ask me anything about the community, or send /help for a quick tour.';
    }

    public function isRetryRequest(string $text): bool
    {
        $q = mb_strtolower(trim($text));
        $q = str_replace(["\u{2019}", "\u{2018}", '`'], "'", $q);
        $q = preg_replace('/\s+/u', ' ', $q) ?? $q;
        $q = rtrim($q, " \t\n\r\0\x0B.!？?~");

        if ($q === '') {
            return false;
        }

        if (in_array($q, ['again', 'retry', 'one more time', 'once more'], true)) {
            return true;
        }

        return (bool) preg_match(
            '/^(?:please\s+)?(?:try(?:\s+(?:again|answering(?:\s+again)?|it(?:\s+again)?))?|answer(?:\s+(?:again|it(?:\s+again)?))?|retry|do\s+it\s+again|say\s+(?:that|it)\s+again)\b/u',
            $q
        );
    }

    /**
     * English-only offline fallback when classify is unavailable.
     * Do not expand into other languages — the model owns multilingual follow-ups.
     */
    public function isContextualFollowUpOffline(string $text): bool
    {
        $raw = trim($text);
        // If a quote-fold wrapper slipped through, judge only the member's current line.
        if (preg_match('/\nCurrent message:\n(.+)$/us', $raw, $m) === 1) {
            return $this->isContextualFollowUpOffline(trim($m[1]));
        }

        $q = mb_strtolower($raw);
        $q = str_replace(["\u{2019}", "\u{2018}", '`'], "'", $q);
        $q = preg_replace('/\s+/u', ' ', $q) ?? $q;
        $q = preg_replace('/^@?zak(?:[_\s-]?bot)?\b[,:]?\s*/u', '', $q) ?? $q;
        $q = rtrim($q, " \t\n\r\0\x0B.!？?~");

        // Thin English offline net only — model owns multilingual follow_up.
        // Do NOT match "list all …" / "summarise …" (those are often new standalone asks).
        if ($q === '' || mb_strlen($q) > 80) {
            return false;
        }

        if (preg_match(
            '/^(?:are you sure|is that (?:true|correct|right|accurate|all)|is this (?:true|correct|right|accurate)|'
            .'really|confirm(?: that)?|you sure|anything else|what else|go (?:on|deeper)|more details?|'
            .'what(?:\'?s| is) being said|what did you (?:mean|say)|what do you mean)\b/u',
            $q
        ) === 1) {
            return true;
        }

        return false;
    }

    /** @deprecated Use isContextualFollowUpOffline — kept for call-site compatibility. */
    public function isContextualFollowUp(string $text): bool
    {
        return $this->isContextualFollowUpOffline($text);
    }

    /**
     * Text to store as the user turn so follow-ups do not replace the prior question
     * in session memory (works for any language once the envelope is built).
     */
    public function userTurnTextToRemember(string $inboundText, string $resolvedQuery): string
    {
        if (str_starts_with(trim($resolvedQuery), 'The member is following up')
            && preg_match('/Original question:\s*(.+?)(?:\n\n|$)/us', $resolvedQuery, $m) === 1) {
            $prior = trim($m[1]);
            if ($prior !== '') {
                return $prior;
            }
        }

        return trim($inboundText) !== '' ? trim($inboundText) : trim($resolvedQuery);
    }

    /**
     * @param  list<array{role: string, text: string}>  $turns
     */
    public function lastAssistantAnswer(array $turns): ?string
    {
        for ($i = count($turns) - 1; $i >= 0; $i--) {
            if (($turns[$i]['role'] ?? '') !== 'assistant') {
                continue;
            }
            $candidate = trim((string) ($turns[$i]['text'] ?? ''));
            if ($candidate === '') {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * Build a knowledge query for a short follow-up on a prior answer.
     */
    public function buildFollowUpKnowledgeQuery(
        string $followUp,
        string $priorQuestion,
        ?string $priorAnswer = null,
    ): string {
        $parts = [
            'The member is following up on a previous community answer.',
            'Original question: '.$priorQuestion,
        ];
        if ($priorAnswer !== null && trim($priorAnswer) !== '') {
            $parts[] = 'Previous answer already shown to the member (do NOT repeat or rephrase these points; '
                .'only add NEW facts/links from community knowledge, or say nothing further remains):'
                ."\n".mb_substr(trim($priorAnswer), 0, 1200);
        }
        $parts[] = 'Follow-up: '.trim($followUp);
        $parts[] = 'Reply formatting: one blank line after any lead sentence before lists; '
            .'clean professional wording; no duplicate sections.';

        return implode("\n\n", $parts);
    }

    /**
     * @param  list<array{role: string, text: string}>  $turns
     */
    public function lastRetrievableUserQuestion(array $turns): ?string
    {
        for ($i = count($turns) - 1; $i >= 0; $i--) {
            if (($turns[$i]['role'] ?? '') !== 'user') {
                continue;
            }
            $candidate = trim((string) ($turns[$i]['text'] ?? ''));
            if ($candidate === '') {
                continue;
            }
            if ($this->isRetryRequest($candidate)) {
                continue;
            }
            if ($this->isContextualFollowUpOffline($candidate)) {
                continue;
            }
            // Skip routing wrappers / follow-up envelopes stored by mistake.
            if (str_starts_with($candidate, 'Regarding this ')
                || str_starts_with($candidate, 'The member is following up')) {
                continue;
            }
            if ($this->isPurelySocial($candidate) || $this->isClearlyOutOfScope($candidate)) {
                continue;
            }
            // Skip short turns that only produced a soft-clarify ping (poisoned follow-ups).
            $next = $turns[$i + 1] ?? null;
            if (is_array($next)
                && ($next['role'] ?? '') === 'assistant'
                && $this->looksLikeClarificationAssistant((string) ($next['text'] ?? ''))) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    public function isPurelySocial(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));
        $normalized = str_replace(["\u{2019}", "\u{2018}", '`'], "'", $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $stripped = rtrim($normalized, " \t\n\r\0\x0B.!？?~");

        if ($stripped === '') {
            return true;
        }

        // Fast-path only for very common short English social turns (skip an LLM round-trip).
        // Any other language / greeting is left to the model classifier.
        $greetings = [
            'hi', 'hello', 'hey', 'howdy', 'yo', 'hiya',
            'good morning', 'good afternoon', 'good evening', 'morning', 'evening',
        ];
        if (in_array($stripped, $greetings, true)) {
            return true;
        }
        if (preg_match('/^(hi|hello|hey)\b/u', $stripped) === 1
            && mb_strlen($stripped) <= 24) {
            return true;
        }

        $thanks = ['thanks', 'thank you', 'thx', 'ty', 'thank you so much', 'thanks a lot'];
        if (in_array($stripped, $thanks, true)
            || preg_match('/^(thanks|thank you)\b/u', $stripped) === 1) {
            return true;
        }

        $goodbyes = ['bye', 'goodbye', 'good bye', 'see you', 'see ya', 'later', 'take care'];
        if (in_array($stripped, $goodbyes, true)) {
            return true;
        }

        $acks = ['ok', 'okay', 'k', 'cool', 'great', 'nice', 'perfect', 'got it', 'alright', 'sure', 'yes', 'yep', 'yeah', 'no', 'nope'];
        if (in_array($stripped, $acks, true)) {
            return true;
        }

        if (preg_match('/^(how are you|how\'?s it going|how are things|what\'?s up)\b/u', $stripped) === 1) {
            return true;
        }

        if (preg_match('/^(who are you|what (?:can|do) you do|help)\b/u', $stripped) === 1) {
            return true;
        }

        if ($this->isChannelPresenceAsk($stripped)) {
            return true;
        }

        // Tone feedback aimed at Zak (English fast-path).
        if (preg_match(
            '/\b(not friendly|unfriendly|rude|mean|cold|unhelpful)\b/u',
            $stripped
        ) === 1
            || preg_match('/^why (?:are|aren\'t|are not) you\b/u', $stripped) === 1) {
            return true;
        }

        // Short bot-directed jabs / compliments — shape only (are you… / you are…).
        // Meaning stays with the model when available; this is a thin offline safety net.
        if ($this->isBotDirectedChat($stripped)) {
            return true;
        }

        return false;
    }

    /**
     * Short turns that address Zak's behavior/ability (not a community-fact ask).
     * Shape-based — not a catalog of insult phrases.
     */
    public function isBotDirectedChat(string $text): bool
    {
        $q = mb_strtolower(trim($text));
        $q = str_replace(["\u{2019}", "\u{2018}", '`'], "'", $q);
        $q = preg_replace('/\s+/u', ' ', $q) ?? $q;
        $q = rtrim($q, " \t\n\r\0\x0B.!？?~");

        if ($q === '' || mb_strlen($q) > 100) {
            return false;
        }

        // Confirmation about a prior answer — not tone aimed at Zak.
        if (preg_match(
            '/^(?:are you (?:sure|certain|serious|ok|okay)|you(?:\'re| are) (?:right|correct|sure))\b/u',
            $q
        ) === 1) {
            return false;
        }

        // "Are you dumb?", "You are mad", "You're useless", "Why are you like this?"
        if (preg_match(
            '/^(?:are you|you are|you\'re|why are you|why aren\'t you|why do you|do you even)\b/u',
            $q
        ) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Questions that are not about community knowledge should not escalate.
     */
    public function isClearlyOutOfScope(string $text): bool
    {
        $q = mb_strtolower(trim($text));
        $q = str_replace(["\u{2019}", "\u{2018}", '`'], "'", $q);
        $q = rtrim($q, " \t\n\r\0\x0B.!？?~");

        if ($q === '') {
            return true;
        }

        // Calculator / pure arithmetic (e.g. "2+2", "15 * 3").
        if (preg_match('/^[\d\s+\-*\/x×÷=().^%]+$/u', $q) === 1) {
            return true;
        }

        if (preg_match('/^(?:what(?:\'?s| is)|calculate|solve|compute)\s+[\d\s+\-*\/x×÷=().^%]+$/iu', $q) === 1) {
            return true;
        }

        // Personal / romantic asks aimed at the bot.
        if (preg_match(
            '/^(?:do you love me|i love you|will you marry me|are we dating|be my (?:boyfriend|girlfriend|valentine))\b/iu',
            $q
        ) === 1) {
            return true;
        }

        if (preg_match(
            '/^(?:do you|are you|can you|will you)\b.+\b(?:love me|marry me|date me|kiss me|have feelings)\b/iu',
            $q
        ) === 1) {
            return true;
        }

        if (preg_match('/^(?:are you (?:real|human|alive|sentient|an? ai)|do you have feelings)\b/iu', $q) === 1) {
            return true;
        }

        // Theology / abstract philosophy with no community angle.
        if (preg_match(
            '/^(?:who(?:\'?s| is)|what is)\s+(?:god|jesus|allah|buddha|religion|love|the meaning of life)\b/iu',
            $q
        ) === 1) {
            return true;
        }

        // Clearly general / entertainment asks with no community angle.
        if (preg_match(
            '/^(?:tell me a joke|write (?:me )?(?:a )?poem|weather (?:in|for|today)|what(?:\'?s| is) the weather|capital of|who won the world cup)\b/iu',
            $q
        ) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Only escalate when the ask looks like missing community knowledge.
     * Off-topic personal / world questions never notify admins.
     */
    public function shouldEscalateKnowledgeGap(string $text, ?string $communityDescription = null): bool
    {
        // Knowledge-path gap: only escalate real community asks.
        // Opaque paste / nonsense must not page admins (model may have mis-routed).
        if ($this->isClearlyOutOfScope($text)
            || $this->isPurelySocial($text)
            || $this->isBotDirectedChat($text)) {
            return false;
        }

        return $this->looksLikeCommunityKnowledgeAsk($text, $communityDescription);
    }

    public function looksLikeCommunityKnowledgeAsk(string $text, ?string $communityDescription = null): bool
    {
        $q = mb_strtolower(trim($text));
        $q = str_replace(["\u{2019}", "\u{2018}", '`'], "'", $q);
        $q = rtrim($q, " \t\n\r\0\x0B.!？?~");

        if ($q === '' || $this->isClearlyOutOfScope($q)) {
            return false;
        }

        // Overlap with this community's published scope blurb.
        if ($this->overlapsCommunityScope($q, $communityDescription)) {
            return true;
        }

        // Shared community / programme content signals.
        if (preg_match(
            '/\b(community|programme|program|session|deadline|schedule|meeting|recording|replay|announcement|hackathon|module|cohort|clinic|onboarding|slides|invite|whatsapp|unipods|wadhwani|coaching|class)\b/u',
            $q
        ) === 1) {
            return true;
        }

        // Who / when / where / how about people, times, places (likely community).
        if (preg_match(
            '/^(?:who(?:\'?s| is| are)|when(?:\'?s| is| are| do| does)|where(?:\'?s| is| are)|how (?:do|does|can|to)|what(?:\'?s| is| are) the)\b/u',
            $q
        ) === 1) {
            if (preg_match('/\b(god|jesus|allah|buddha|religion|meaning of life)\b/u', $q) === 1) {
                return false;
            }

            return true;
        }

        // "Who Diane" / "Who dianee" (missing "is") and similar name lookups.
        if ($this->looksLikePersonLookup($q)) {
            return true;
        }

        // Explicit ask for shared links / files / notes (English offline fallback).
        if (preg_match(
            '/\b(send|share|give|find|get|list)\b.+\b(link|links|recording|recordings|notes|doc|docs|file|files|url|urls)\b/u',
            $q
        ) === 1) {
            return true;
        }

        return false;
    }

    /**
     * English-only offline URL-list hint when AI classify is down.
     * Multilingual link_mode comes from the model (any language).
     *
     * @return 'none'|'recordings'|'meetings'|'assets'
     */
    public function inferLinkMode(string $query): string
    {
        $q = mb_strtolower(trim($query));
        if ($q === '') {
            return 'none';
        }

        // "recap" alone often means catch-up, not video recordings.
        // Only treat as recordings when paired with media/session-video cues.
        if (preg_match('/\b(recording|recordings|replay|youtube|youtu\.?be)\b/u', $q) === 1
            || (
                preg_match('/\brecap\b/u', $q) === 1
                && preg_match('/\b(recording|recordings|video|videos|session|youtube|youtu\.?be|link|links)\b/u', $q) === 1
            )
        ) {
            return 'recordings';
        }

        if (preg_match('/\b(meeting link|zoom|google meet|teams link|join (the )?call)\b/u', $q) === 1) {
            return 'meetings';
        }

        if (preg_match('/\b(slides?|deck|pdf|docs?|files?|assets?|documents?|guidelines?|handbook)\b/u', $q) === 1
            && preg_match('/\b(send|share|give|find|get|list|link|links)\b/u', $q) === 1) {
            return 'assets';
        }

        return 'none';
    }

    /**
     * Merge AI classify with offline heuristics.
     * Prefer the model for intent + link_mode + follow_up in every language; only override a
     * wrong out_of_scope when offline already marks the turn as community knowledge
     * (including generic non-ASCII asks — no per-language keyword lists).
     *
     * @param  array{intent: string, query: string, link_mode?: string}  $resolved
     * @param  array{intent?: string, link_mode?: string, follow_up?: bool}|null  $classified
     * @param  list<array{role: string, text: string}>  $turns
     * @return array{intent: string, query: string, link_mode: string}
     */
    public function mergeModelClassification(
        array $resolved,
        ?array $classified,
        ?string $communityDescription = null,
        array $turns = [],
        bool $replyToBot = false,
    ): array {
        $query = (string) ($resolved['query'] ?? '');
        $offlineLink = $this->inferLinkMode($query);
        $offlineIntent = $this->classifyIntent($query, $communityDescription);
        $resolved['link_mode'] = (string) ($resolved['link_mode'] ?? 'none');
        $prior = $this->lastRetrievableUserQuestion($turns);

        if ($classified === null) {
            // Swipe-reply: only force follow-up when the offline heuristic agrees —
            // never hard-code followUpFlag=true (that wraps fresh asks onto stale priors).
            if ($replyToBot && $prior !== null && $this->shouldForceSessionFollowUp($query, null, false)) {
                return $this->asFollowUpKnowledge($resolved, $query, $prior, $turns);
            }

            if ($resolved['link_mode'] === 'none') {
                $resolved['link_mode'] = $offlineLink;
            }
            // Offline focus: prefer one for a single-resource ask; many when the
            // ask clearly lists several (and/all/both) or plural resource words.
            if (($resolved['link_mode'] ?? 'none') === 'none') {
                $resolved['link_focus'] = 'na';
            } elseif (preg_match('/\b(and|all|both)\b/iu', $query) === 1
                || preg_match('/\b(links|recordings|slides|files|documents)\b/iu', $query) === 1) {
                $resolved['link_focus'] = 'many';
            } else {
                $resolved['link_focus'] = 'one';
            }

            return $resolved;
        }

        $modelIntent = (string) ($classified['intent'] ?? '');
        $linkMode = (string) ($classified['link_mode'] ?? 'none');
        $linkFocus = strtolower(trim((string) ($classified['link_focus'] ?? 'na')));
        if (! in_array($linkFocus, ['one', 'many', 'na'], true)) {
            $linkFocus = 'na';
        }
        $followUp = filter_var($classified['follow_up'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $applyFocus = function (array $resolved) use ($linkFocus): array {
            $mode = (string) ($resolved['link_mode'] ?? 'none');
            if ($mode === 'none') {
                $resolved['link_focus'] = 'na';
            } elseif ($linkFocus === 'one') {
                $resolved['link_focus'] = 'one';
            } else {
                $resolved['link_focus'] = 'many';
            }

            return $resolved;
        };

        // Swipe-reply to our answer: never soft-clarify into a new topic / poison session.
        if ($replyToBot && $prior !== null && $this->shouldForceSessionFollowUp($query, $modelIntent, $followUp)) {
            if ($this->isNearDuplicateAsk($query, $prior)) {
                $resolved['intent'] = self::INTENT_KNOWLEDGE;
                $resolved['query'] = $query;
                $resolved['link_mode'] = $linkMode !== 'none' ? $linkMode : $offlineLink;

                return $applyFocus($resolved);
            }

            return $applyFocus($this->asFollowUpKnowledge($resolved, $query, $prior, $turns));
        }

        // Model-owned follow_up — but never glue tone/insults onto a prior knowledge ask.
        if ($followUp && $prior !== null) {
            if ($modelIntent === self::INTENT_CONVERSATIONAL
                || $modelIntent === self::INTENT_PERSONAL_HELP
                || $this->isPurelySocial($query)
                || $this->isBotDirectedChat($query)) {
                $resolved['intent'] = self::INTENT_CONVERSATIONAL;
                $resolved['link_mode'] = 'none';

                return $applyFocus($resolved);
            }

            // Same / near-same ask again → fresh knowledge search, not an envelope.
            if ($this->isNearDuplicateAsk($query, $prior)) {
                $resolved['intent'] = self::INTENT_KNOWLEDGE;
                $resolved['query'] = $query;
                $resolved['link_mode'] = $linkMode !== 'none' ? $linkMode : $offlineLink;

                return $applyFocus($resolved);
            }

            return $applyFocus($this->asFollowUpKnowledge($resolved, $query, $prior, $turns));
        }

        if ($modelIntent === 'clarify') {
            $resolved['intent'] = 'clarify';
            $resolved['link_mode'] = 'none';

            return $applyFocus($resolved);
        }

        // Honor model conversational / personal_help over offline knowledge heuristics.
        if ($modelIntent === self::INTENT_CONVERSATIONAL
            || $modelIntent === self::INTENT_PERSONAL_HELP) {
            $resolved['intent'] = $modelIntent;
            $resolved['link_mode'] = 'none';

            return $applyFocus($resolved);
        }

        // Un-stick model OOS only when offline signals a real community ask — not when
        // offline merely defaults to "prefer search" for unknown English.
        $preferKnowledge = $this->looksLikeCommunityKnowledgeAsk($query, $communityDescription)
            || $this->looksLikePersonLookup($query)
            || $this->looksLikeNonEnglishCommunityAsk($query);

        if ($modelIntent === self::INTENT_OUT_OF_SCOPE && $preferKnowledge) {
            $resolved['intent'] = self::INTENT_KNOWLEDGE;
            $resolved['link_mode'] = $linkMode !== 'none' ? $linkMode : $offlineLink;

            return $applyFocus($resolved);
        }

        // Model mis-fired out_of_scope for a normal assistant turn — only honor OOS when
        // offline hard-refuse agrees (math, jokes, weather, etc.).
        if ($modelIntent === self::INTENT_OUT_OF_SCOPE && ! $this->isClearlyOutOfScope($query)) {
            $resolved['intent'] = self::INTENT_CONVERSATIONAL;
            $resolved['link_mode'] = 'none';

            return $applyFocus($resolved);
        }

        $resolved['intent'] = $modelIntent !== '' ? $modelIntent : $offlineIntent;
        // Prefer model link_mode (works in any language); English offline is fallback only.
        $resolved['link_mode'] = $linkMode !== 'none' ? $linkMode : $offlineLink;

        return $applyFocus($resolved);
    }

    /**
     * When to keep the prior knowledge topic instead of clarifying / swapping.
     * Structural (reply_to_bot + prior + model signals) — not a language keyword catalog.
     */
    public function shouldForceSessionFollowUp(
        string $query,
        ?string $modelIntent,
        bool $followUpFlag,
    ): bool {
        // Never force a knowledge follow-up for tone / bot-directed chat.
        if ($modelIntent === self::INTENT_CONVERSATIONAL
            || $modelIntent === self::INTENT_PERSONAL_HELP
            || $this->isPurelySocial($query)
            || $this->isBotDirectedChat($query)) {
            return false;
        }

        if ($followUpFlag || $this->isContextualFollowUpOffline($query)) {
            return true;
        }

        // Model soft-clarified a swipe continuation — keep the prior topic.
        if ($modelIntent === 'clarify') {
            return true;
        }

        return false;
    }

    /**
     * @param  array{intent: string, query: string, link_mode?: string}  $resolved
     * @param  list<array{role: string, text: string}>  $turns
     * @return array{intent: string, query: string, link_mode: string}
     */
    private function asFollowUpKnowledge(
        array $resolved,
        string $memberLine,
        string $prior,
        array $turns,
    ): array {
        if (str_starts_with(trim($memberLine), 'The member is following up')) {
            $resolved['intent'] = self::INTENT_KNOWLEDGE;
            $resolved['link_mode'] = 'none';

            return $resolved;
        }

        $resolved['intent'] = self::INTENT_KNOWLEDGE;
        $resolved['link_mode'] = 'none';
        $resolved['query'] = $this->buildFollowUpKnowledgeQuery(
            $memberLine,
            $prior,
            $this->lastKnowledgeAssistantAnswer($turns),
        );

        return $resolved;
    }

    /**
     * Last assistant answer that was real community content (skip soft clarify pings).
     *
     * @param  list<array{role: string, text: string}>  $turns
     */
    public function lastKnowledgeAssistantAnswer(array $turns): ?string
    {
        for ($i = count($turns) - 1; $i >= 0; $i--) {
            if (($turns[$i]['role'] ?? '') !== 'assistant') {
                continue;
            }
            $candidate = trim((string) ($turns[$i]['text'] ?? ''));
            if ($candidate === '' || $this->looksLikeClarificationAssistant($candidate)) {
                continue;
            }
            if ($this->looksLikeEscalationOrSnagAssistant($candidate)) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * Soft handoff / transient failure copy must not become "Previous answer" context.
     */
    public function looksLikeEscalationOrSnagAssistant(string $text): bool
    {
        $q = mb_strtolower(trim($text));
        if ($q === '') {
            return false;
        }

        return str_contains($q, "don't have a solid answer")
            || str_contains($q, 'passed it along')
            || str_contains($q, 'hit a snag')
            || str_contains($q, 'mind sending it again')
            || str_contains($q, "i'll reply as soon as i can")
            || str_contains($q, 'no need to send it again')
            || str_contains($q, "i've got your message");
    }

    /**
     * Near-duplicate of a prior ask (retry / typo) — search fresh, do not wrap as follow-up.
     */
    public function isNearDuplicateAsk(string $current, string $prior): bool
    {
        $a = $this->normalizeAskForCompare($current);
        $b = $this->normalizeAskForCompare($prior);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }

        similar_text($a, $b, $percent);

        return $percent >= 82.0;
    }

    private function normalizeAskForCompare(string $text): string
    {
        $q = mb_strtolower(trim($text));
        $q = str_replace(["\u{2019}", "\u{2018}", '`'], "'", $q);
        $q = preg_replace('/^@?zak(?:[_\s-]?bot)?\b[,:]?\s*/u', '', $q) ?? $q;
        $q = preg_replace('/\s+/u', ' ', $q) ?? $q;
        $q = rtrim($q, " \t\n\r\0\x0B.!？?~");

        return $q;
    }

    public function looksLikeClarificationAssistant(string $text): bool
    {
        $q = mb_strtolower(trim($text));
        if ($q === '') {
            return false;
        }

        return str_contains($q, 'could you clarify')
            || str_contains($q, 'what you mean')
            || str_contains($q, 'what you\'re asking')
            || str_contains($q, 'say a bit more about what you need')
            || str_contains($q, 'looking for more information on a specific topic');
    }

    /**
     * Prefer knowledge search for non-ASCII community-looking asks when the model is offline.
     * Not a language catalog: any accented / non-Latin script text of reasonable length.
     */
    public function looksLikeNonEnglishCommunityAsk(string $text): bool
    {
        $q = trim($text);
        if ($q === '' || $this->isClearlyOutOfScope($q) || $this->isPurelySocial($q)) {
            return false;
        }

        // Letters outside basic ASCII (accents, tone marks, Arabic, etc.)
        if (preg_match('/[^\x00-\x7F]/u', $q) === 1 && mb_strlen($q) >= 8) {
            return true;
        }

        return false;
    }

    public function outOfScopeReply(
        ?string $communityName = null,
        ?string $communityDescription = null,
        string $style = 'plain',
    ): string {
        $label = trim((string) $communityName);
        $where = $label !== '' ? $label : 'this community';
        $focus = $this->friendlyScopeSummary($communityDescription);

        if ($style === 'whatsapp') {
            return "Sorry, I can't help with that one 🙂\n\n"
                ."In *{$where}*, I can help with {$focus}.\n\n"
                .$this->anyLanguageHint()."\n\n"
                ."Ask me anything about the community anytime. "
                .$this->followUpReassurance()."\n\n"
                .$this->askAdminHint('whatsapp');
        }

        return "Sorry, I can't help with that one 🙂\n\n"
            ."In {$where}, I can help with {$focus}.\n\n"
            .$this->anyLanguageHint()."\n\n"
            ."Ask me anything about the community anytime. "
            .$this->followUpReassurance()."\n\n"
            .$this->askAdminHint('plain');
    }

    /**
     * Remove internal evidence markers members should never see:
     * [E1], [E1, E6, E5, E2], [E1 (02:15)], and state footers like "(…, POSSIBLE)".
     */
    public function stripInternalEvidenceTags(string $answer): string
    {
        $answer = trim($answer);
        $answer = preg_replace(
            '/\n*\([^)\n]*,\s*(POSSIBLE|VERIFIED|CONFLICT|BLOCKED|INSUFFICIENT(?:_EVIDENCE)?)\)\s*$/iu',
            '',
            $answer
        ) ?? $answer;
        // Grouped or single: [E1], [E1, E6, E5, E2], optional timestamps/labels inside.
        $answer = preg_replace(
            '/\s*\[\s*E\d+(?:\s*\([^)]*\))?(?:\s*,\s*E\d+(?:\s*\([^)]*\))?)*\s*\]/u',
            '',
            $answer
        ) ?? $answer;
        // Cleanup leftover spaces before punctuation.
        $answer = preg_replace('/[ \t]+([.,;:!?])/u', '$1', $answer) ?? $answer;
        $answer = preg_replace("/[ \t]+\n/u", "\n", $answer) ?? $answer;

        return trim($answer);
    }

    /**
     * Member-facing polish: strip internal tags + clean garbled chat-export dumps.
     */
    public function tidyMemberAnswer(string $answer): string
    {
        $answer = $this->stripInternalEvidenceTags($answer);
        if ($answer === '') {
            return '';
        }

        // Drop obvious mojibake / emoji-noise runs from scraped chat exports.
        $answer = preg_replace('/[\x{1F300}-\x{1FAFF}]{3,}/u', '', $answer) ?? $answer;
        $answer = preg_replace('/[^\P{C}\n\t]+/u', '', $answer) ?? $answer;

        // Collapse "1:36] ~ Name:" chat-log crumbs into cleaner lines.
        $answer = preg_replace('/\b\d{1,2}:\d{2}\]\s*/u', '', $answer) ?? $answer;
        $answer = preg_replace('/~\s*/u', '', $answer) ?? $answer;

        // Drop internal non-member schemes the model may paste from source_uri.
        $answer = preg_replace(
            '/^\s*\d+[\).\:\-]\s*[^\n]*\n\s*(?:whatsapp|telegram-spike|community|probe):\/\/[^\s]+[^\n]*\n?/imu',
            '',
            $answer
        ) ?? $answer;
        $answer = preg_replace(
            '/(?:whatsapp|telegram-spike|community|probe):\/\/[^\s]+/iu',
            '',
            $answer
        ) ?? $answer;

        // Prefer LinkedIn items as "Name — url" when a raw dump is mostly profile links.
        if (preg_match_all('#https?://(?:www\.)?linkedin\.com/[^\s)\]>]+#iu', $answer, $m) >= 2) {
            $urls = array_values(array_unique($m[0]));
            $lines = preg_split("/\n+/u", $answer) ?: [];
            $items = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $line = preg_replace('/^\d+[\).\:\-]\s*/u', '', $line) ?? $line;
                if (preg_match('#https?://(?:www\.)?linkedin\.com/[^\s)\]>]+#iu', $line, $um) !== 1) {
                    continue;
                }
                $url = $um[0];
                $label = trim(str_replace($url, '', $line));
                $label = trim($label, " \t-:|,.");
                // Pull a human name if present.
                if (preg_match('/(?:I am|I\'m|am|name is)\s+([A-Z][\p{L}\'.\-]+(?:\s+[A-Z][\p{L}\'.\-]+){0,3})/u', $label, $nm) === 1) {
                    $label = $nm[1];
                } elseif (preg_match('/\b([A-Z][\p{L}\'.\-]+(?:\s+[A-Z][\p{L}\'.\-]+){1,3})\b/u', $label, $nm) === 1) {
                    $label = $nm[1];
                } else {
                    $label = '';
                }
                $items[] = $label !== '' ? "{$label}\n{$url}" : $url;
            }

            if (count($items) >= 2) {
                $list = [];
                foreach ($items as $i => $item) {
                    $list[] = ($i + 1).'. '.$item;
                }

                return "Here are recent intros from the group:\n\n".implode("\n\n", $list);
            }

            // Fallback: clean URL list only.
            $list = [];
            foreach ($urls as $i => $url) {
                $list[] = ($i + 1).'. '.$url;
            }

            return "Here are recent LinkedIn profiles from the group:\n\n".implode("\n", $list);
        }

        $answer = preg_replace("/\n{3,}/u", "\n\n", $answer) ?? $answer;
        // Blank line after a short lead before a numbered list (readable on phones).
        $answer = preg_replace(
            '/(^|\n)([^\n\d][^\n]{0,120})\n(\d+[\).\:\-]\s+)/u',
            "$1$2\n\n$3",
            $answer,
        ) ?? $answer;

        // Models often copy HTML-escaped evidence (&amp;); restore real URLs.
        $answer = preg_replace_callback(
            '#https?://[^\s<>"\']+#iu',
            static function (array $m): string {
                return html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            },
            $answer,
        ) ?? $answer;

        return trim($answer);
    }

    /**
     * English /ask help footer — only when both the member and the reply are English.
     * Never bolt English commands onto Amharic / French / etc.
     */
    public function shouldAppendEnglishAskHint(string $reply, ?string $memberMessage = null): bool
    {
        $reply = trim($reply);
        if ($reply === '' || str_contains(mb_strtolower($reply), '/ask')) {
            return false;
        }

        if (! $this->looksLikeEnglishChatText($reply)) {
            return false;
        }

        if ($memberMessage !== null && trim($memberMessage) !== '' && ! $this->looksLikeEnglishChatText($memberMessage)) {
            return false;
        }

        return true;
    }

    /**
     * True when text is basically English (ASCII letters). Any other-script
     * or accented non-English chat stays language-pure — no English footer.
     */
    public function looksLikeEnglishChatText(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return true;
        }

        // Drop emoji / common punctuation so they don't block English detection.
        $stripped = preg_replace(
            '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE00}-\x{FE0F}\x{200D}\x{2010}-\x{2027}\x{2030}-\x{205E}]/u',
            '',
            $text
        ) ?? $text;

        // Any remaining non-ASCII letter/syllable → not English-only chat.
        if (preg_match('/[^\x00-\x7F]/u', $stripped) === 1) {
            return false;
        }

        $letters = preg_replace('/[^a-zA-Z]/', '', $stripped) ?? '';

        return $letters !== '';
    }

    /**
     * How members can escalate to an admin or suggest a note when Zak cannot help.
     *
     * @param  'plain'|'whatsapp'  $style
     */
    public function askAdminHint(string $style = 'plain'): string
    {
        if ($style === 'whatsapp') {
            // Keep short — same meaning as Telegram, mobile-friendly.
            return "*Still need help?* Send:\n"
                ."```\n"
                ."/ask your question\n"
                ."```\n"
                ."_e.g._ ".$this->highlightCommand('/ask', 'whatsapp')." When is the next session?\n\n"
                ."*Something the community should know?*\n"
                ."```\n"
                ."/share Clinic closed Friday afternoon\n"
                ."```";
        }

        return "Still need help on a community question? Send:\n"
            ."/ask your community question here\n"
            ."   e.g. /ask When is the next UniPods session?\n"
            ."(If {$this->botDisplayName()} already knows the answer, /ask replies right away.)\n\n"
            ."Something the community should know? Send:\n"
            ."/share Clinic closed Friday afternoon\n"
            ."(An admin reviews it before {$this->botDisplayName()} can use it in answers.)";
    }

    /**
     * Polite note that Zak escalates and follows up (no need to keep pinging).
     */
    public function followUpReassurance(): string
    {
        return "If I don't have an answer right now, I'll say so, pass it along, "
            .'and follow up once I do. No need to keep checking or asking again.';
    }

    /**
     * Members can write in any language; Zak mirrors that language.
     */
    public function anyLanguageHint(): string
    {
        return "You can ask in any language; I'll reply in the same one.";
    }

    /**
     * After the listen gate passes: is this turn actually speaking to Zak
     * and expecting a reply? Avoids blind answers on incidental @mentions
     * or swipe-replies that address someone else.
     *
     * @param  array{
     *   chat_type?: string,
     *   reply_to_bot?: bool,
     *   bot_mentioned?: bool,
     *   bot_aliases?: list<string>,
     *   quoted_text?: string,
     * }  $ctx
     * @return bool|null  true / false when decisive; null when ambiguous (caller may ask AI)
     */
    public function expectsBotResponse(string $text, array $ctx = []): ?bool
    {
        $chatType = strtolower(trim((string) ($ctx['chat_type'] ?? 'private')));
        $isPrivate = $chatType === '' || $chatType === 'private' || $chatType === 'dm';
        if ($isPrivate) {
            return true;
        }

        $raw = trim($text);
        if ($raw === '') {
            return false;
        }

        if ($this->startsWithChannelCommand($raw)) {
            return true;
        }

        $aliases = [];
        foreach ($ctx['bot_aliases'] ?? [] as $alias) {
            $alias = trim((string) $alias);
            if ($alias !== '') {
                $aliases[] = $alias;
            }
        }

        $replyToBot = filter_var($ctx['reply_to_bot'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $botMentioned = filter_var($ctx['bot_mentioned'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $quoted = trim((string) ($ctx['quoted_text'] ?? ''));

        $body = $this->stripBotAddressing($raw, $aliases);
        $lower = mb_strtolower($body);

        // Talking about Zak in the third person, not to Zak.
        if (preg_match(
            '/\b(?:tell|ask|remind|ping|forward(?:\s+to)?|show)\s+(?:zak|the\s+bot)\b/u',
            $lower
        ) === 1) {
            return false;
        }
        if (preg_match('/\b(?:zak|the\s+bot)\s+(?:said|says|told|thinks|will|can|should)\b/u', $lower) === 1
            && ! $this->looksLikeDirectBotAsk($body)) {
            return false;
        }

        // FYI / CC-only tags with no real ask for Zak.
        if (preg_match('/^(?:cc|fyi|fwd|forwarding)(?:\s*[:\-–]|\s+)/u', $lower) === 1
            && ! $this->looksLikeDirectBotAsk($body)) {
            return false;
        }

        // Primary addressee is another @person (not the bot) → not for Zak,
        // unless it is clearly a who/what ask *about* that person.
        if ($this->addressesOtherPersonFirst($body, $aliases)
            && ! $this->looksLikeAskAboutMentionedPerson($body)) {
            return false;
        }

        // Group listen without mention/reply (e.g. bare chatter): not for Zak.
        if (! $botMentioned && ! $replyToBot) {
            return false;
        }

        // Bare @zak / @LID while quoting another message = maybe "answer this".
        // Still run directed-at checks on the quote (incidental quote ≠ an ask for Zak).
        if ($botMentioned
            && ($body === '' || preg_match('/^@?\d{6,}$/u', $body) === 1)
            && $quoted !== '') {
            return $this->expectsBotResponse($quoted, [
                'chat_type' => $chatType,
                'reply_to_bot' => false,
                'bot_mentioned' => true,
                'bot_aliases' => $aliases,
                'quoted_text' => '',
            ]);
        }

        // Bare @zak alone (or @bot LID only) = friendly ping / continue thread.
        if ($botMentioned && ($body === '' || preg_match('/^@?\d{6,}$/u', $body) === 1) && $quoted === '') {
            return true;
        }

        // Swipe-reply / mention clearly asking Zak (or following up with Zak).
        if ($this->looksLikeDirectBotAsk($body)) {
            return true;
        }

        // Short social to Zak after a mention/reply.
        if ($this->looksLikeBotSocial($body)) {
            return true;
        }

        // Reply to Zak with empty/noise only.
        if ($replyToBot && mb_strlen($body) < 2) {
            return false;
        }

        // Incidental tag with no question / ask for Zak.
        if ($botMentioned && ! $replyToBot && ! $this->looksLikeDirectBotAsk($body)
            && ! $this->looksLikeBotSocial($body)) {
            // Ambiguous: tagged + some text that isn't clearly an ask
            return null;
        }

        // Swipe-reply to Zak with unclear text (maybe answering someone else in-thread).
        if ($replyToBot) {
            // If they quote Zak's escalation card, admin path handles separately.
            $qLower = mb_strtolower($quoted);
            if (str_contains($qLower, 'request id:') && str_contains($qLower, 'zak needs a quick hand')) {
                return true;
            }

            return null;
        }

        return null;
    }

    /**
     * @param  list<string>  $aliases
     */
    private function stripBotAddressing(string $text, array $aliases): string
    {
        $out = trim($text);
        // Leading "hey zak," / "zak:" / "@zak_bot"
        $out = preg_replace(
            '/^(?:hey|hi|hello|ok|okay)?\s*[,:]?\s*(?:@)?zak(?:[_\s-]?bot)?\b[,:]?\s*/iu',
            '',
            $out
        ) ?? $out;

        foreach ($aliases as $alias) {
            $a = ltrim($alias, '@');
            if ($a === '') {
                continue;
            }
            $quoted = preg_quote($a, '/');
            $out = preg_replace('/^(?:hey|hi|hello)?\s*[,:]?\s*@?'.$quoted.'\b[,:]?\s*/iu', '', $out) ?? $out;
            $out = preg_replace('/@'.$quoted.'(?!\d)/iu', ' ', $out) ?? $out;
        }

        return trim(preg_replace('/\s+/u', ' ', $out) ?? $out);
    }

    private function looksLikeDirectBotAsk(string $body): bool
    {
        $body = trim($body);
        if ($body === '') {
            return false;
        }

        $lower = mb_strtolower($body);

        if (str_contains($body, '?') || str_contains($body, '？')) {
            return true;
        }

        // Imperative / request verbs commonly used with Zak.
        if (preg_match(
            '/^(?:please\s+)?(?:what|when|where|who|why|how|which|can|could|would|will|do|does|did|is|are|was|were|tell|show|send|share|list|give|explain|summar(?:y|ise|ize)|remind|check|find|help)\b/u',
            $lower
        ) === 1) {
            return true;
        }

        if (preg_match(
            '/\b(?:what|when|where|who|why|how)\b.+\b(?:is|are|was|were|do|does|did|can|should)\b/u',
            $lower
        ) === 1) {
            return true;
        }

        return false;
    }

    private function looksLikeBotSocial(string $body): bool
    {
        $lower = mb_strtolower(trim($body));
        if ($lower === '') {
            return false;
        }

        return (bool) preg_match(
            '/^(?:hi|hello|hey|thanks|thank you|thx|ty|ok|okay|cool|great|bye|good(?:\s+morning|\s+night)?|how are you|who are you|what can you do)[!.,\s]*$/u',
            $lower
        );
    }

    /**
     * True when the message opens by addressing another @person (not Zak).
     *
     * @param  list<string>  $aliases
     */
    private function addressesOtherPersonFirst(string $body, array $aliases): bool
    {
        if (preg_match('/^@([^\s@]+)/u', trim($body), $m) !== 1) {
            return false;
        }

        $tag = ltrim($m[1], '@');
        $tagLower = mb_strtolower($tag);
        foreach ($aliases as $alias) {
            $a = mb_strtolower(ltrim((string) $alias, '@'));
            if ($a !== '' && ($tagLower === $a || str_starts_with($tagLower, $a))) {
                return false;
            }
        }
        if (str_starts_with($tagLower, 'zak')) {
            return false;
        }

        // Digit mentions (WA) left after stripBotAddressing are other people.
        return true;
    }

    /**
     * "@Joy who is she?" / "Who's @Joy" still for Zak.
     * "@diane can you help?" is for Diane, not Zak.
     */
    private function looksLikeAskAboutMentionedPerson(string $body): bool
    {
        $trimmed = trim($body);
        // "Who's @Joy …" / "What about @Joy …"
        if (preg_match('/^(?:who(?:\'?s|\s+is|\s+are)?|what about|tell me about)\b/iu', $trimmed) === 1) {
            return true;
        }

        $rest = trim((string) preg_replace('/^@[^\s@]+\s*/u', '', $trimmed));
        if ($rest === '') {
            return false;
        }

        // Imperatives / questions aimed at that person ("can you…", "what do you think?").
        if (preg_match('/^(?:can|could|would|will|please|do|did|are|is)\s+you\b/iu', $rest) === 1) {
            return false;
        }
        if (preg_match('/\bdo you\b|\bare you\b|\bcan you\b|\bcould you\b/iu', $rest) === 1) {
            return false;
        }
        if (preg_match('/^(?:please|help|check|send|share|confirm)\b/iu', $rest) === 1) {
            return false;
        }

        return preg_match('/^(?:who|what|when|where|why|how)\b/iu', $rest) === 1
            || preg_match('/^(?:tell me about|what about)\b/iu', $rest) === 1
            || $this->looksLikePersonLookup($rest);
    }

    private function startsWithChannelCommand(string $text): bool
    {
        $trimmed = ltrim($text);
        if ($trimmed === '') {
            return false;
        }

        if (preg_match('/^\/?([a-z]+)\b/iu', $trimmed, $m) !== 1) {
            return false;
        }

        $cmd = strtolower($m[1]);

        return in_array($cmd, array_merge(
            ChannelListenGate::MEMBER_COMMANDS,
            ChannelListenGate::ADMIN_COMMANDS,
        ), true) || str_starts_with(strtoupper($trimmed), 'JOIN-');
    }

    /**
     * Member /share confirmation after the note is queued for admin review.
     */
    public function shareQueuedReply(bool $adminNotified = true): string
    {
        if ($adminNotified) {
            return "Got it, thanks for sharing 🙂\n\n"
                ."I've sent that to an admin for review.\n"
                ."If they approve it, I can use it in answers.\n\n"
                ."I'll message you when they decide.";
        }

        return "Got it, thanks for sharing 🙂\n\n"
            ."I've saved it as a draft for an admin to review.\n"
            ."If they approve it, I can use it in answers.";
    }

    /**
     * Quiet ack when an admin message was auto-published to knowledge.
     */
    public function adminKnowledgeIndexedAck(): string
    {
        return 'Noted. I saved that to the knowledge base so I can use it in answers.';
    }

    public function voiceNoteFailedReply(): string
    {
        return "I do listen to voice notes 🎧 — I just couldn't read that one clearly.\n\n"
            .'Try sending it again, or type your question in text.';
    }

    public function voiceNoteTooLongReply(): string
    {
        return "That voice note is a bit long for me right now ⏱️\n\n"
            .'Try a shorter clip (about a minute), or type the question.';
    }

    public function imageNoteFailedReply(): string
    {
        return "I can read photos 📷. I just couldn't make that one out clearly.\n\n"
            .'Try sending it again, send a voice note, or type what you need.';
    }

    public function imageNoteTooLargeReply(): string
    {
        return "That photo is a bit large for me right now 📷\n\n"
            .'Try a smaller picture, or type the question.';
    }

    /**
     * Soft deferral when a turn fails transiently (sync snag or after queue retries).
     * Industry pattern: acknowledge receipt + set expectation — do not ask them to resend.
     * Jobs should rethrow so Redis retries; only send this on final failure / sync catch.
     */
    public function transientDeferralReply(): string
    {
        return "Thanks — I've got your message 🙂\n\n"
            ."I'll reply as soon as I can. No need to send it again.";
    }

    /**
     * Highlight a slash command for the channel (WhatsApp bold / Telegram <code>).
     *
     * @param  'whatsapp'|'telegram_html'|'plain'  $style
     */
    public function highlightCommand(string $command, string $style = 'whatsapp'): string
    {
        $command = trim($command);
        if ($command === '') {
            return '';
        }
        if (! str_starts_with($command, '/')) {
            $command = '/'.$command;
        }

        if ($style === 'telegram_html') {
            return '<code>'.$this->escapeTelegramHtml($command).'</code>';
        }

        if ($style === 'whatsapp') {
            // Bold stands out more than monospace for scan-heavy /help cards.
            return '*'.$command.'*';
        }

        return $command;
    }

    /**
     * Wrap known bot commands in channel formatting anywhere they appear in copy.
     * Skips already-formatted spans and never touches URL path segments.
     *
     * @param  'whatsapp'|'telegram_html'|'plain'  $style
     */
    public function formatCommandsInText(string $text, string $style = 'whatsapp'): string
    {
        if ($style === 'plain' || $text === '') {
            return $text;
        }

        $pattern = '/\*[^*]+\*|```[\s\S]*?```|<code>[\s\S]*?<\/code>|\/(?:ask|share|feature|help|join|start|export|import|asset|publish|knowledge|kb|features|approve|decline|reply|blacklist|unblacklist)\b/iu';

        $formatted = preg_replace_callback(
            $pattern,
            function (array $m) use ($style): string {
                $token = $m[0];
                if (str_starts_with($token, '*')
                    || str_starts_with($token, '```')
                    || str_starts_with($token, '<code>')) {
                    return $token;
                }

                return $this->highlightCommand($token, $style);
            },
            $text
        );

        return is_string($formatted) ? $formatted : $text;
    }

    /**
     * Empty /feature usage: request a new product feature or improve an existing one.
     *
     * @param  'whatsapp'|'plain'  $style
     */
    public function featureUsageReply(string $style = 'whatsapp'): string
    {
        if ($style === 'whatsapp') {
            $cmd = $this->highlightCommand('/feature', 'whatsapp');
            $newFeature = $this->emphasisLabel('new feature', 'whatsapp');
            $improve = $this->emphasisLabel('improve', 'whatsapp');

            return "Use {$cmd} to ask for a {$newFeature}, or to {$improve} something that already exists.\n\n"
                ."Examples:\n"
                ."```\n"
                ."/feature Add reminders for upcoming sessions\n"
                ."/feature Make group replies shorter\n"
                ."```\n\n"
                .'An admin will review it, and I\'ll reply here when they respond.';
        }

        return "Use /feature to ask for a new feature, or to improve something that already exists.\n\n"
            ."Examples:\n"
            ."/feature Add reminders for upcoming sessions\n"
            ."/feature Make group replies shorter\n\n"
            .'An admin will review it, and I\'ll reply here when they respond.';
    }

    /**
     * Member /feature confirmation after the request is queued for admin review.
     */
    public function featureQueuedReply(bool $adminNotified = true): string
    {
        if ($adminNotified) {
            return "Thanks for the suggestion 🙂\n\n"
                ."I've sent your *feature request* to an *admin*.\n"
                .'You\'ll get a reply here once they respond.';
        }

        return "Thanks for the suggestion 🙂\n\n"
            ."I've logged your *feature request* for an *admin* to review.\n"
            .'You\'ll hear back once they respond.';
    }

    /**
     * Member ping after an admin approves their /feature request.
     *
     * @param  'whatsapp'|'telegram_html'  $style
     */
    public function featureApprovedMemberReply(
        ?string $fromName = null,
        ?string $request = null,
        string $style = 'whatsapp',
    ): string {
        $hi = ($fromName !== null && trim($fromName) !== '')
            ? trim($fromName).",\n\n"
            : '';
        $request = trim((string) $request);
        $detail = $request !== ''
            ? "\n\n".$this->emphasisLabel('Your request:', $style)."\n".$this->escapeChannelBody($request, $style)
            : '';

        return $hi
            ."Good news: an admin approved your feature request 🙂{$detail}\n\n"
            .'Thanks for helping improve the community experience.';
    }

    /**
     * Member ping after an admin declines their /feature request.
     *
     * @param  'whatsapp'|'telegram_html'  $style
     */
    public function featureDeclinedMemberReply(
        ?string $fromName = null,
        ?string $request = null,
        string $style = 'whatsapp',
    ): string {
        $hi = ($fromName !== null && trim($fromName) !== '')
            ? trim($fromName).",\n\n"
            : '';
        $request = trim((string) $request);
        $detail = $request !== ''
            ? "\n\n".$this->emphasisLabel('Your request:', $style)."\n".$this->escapeChannelBody($request, $style)
            : '';
        $cmd = $this->highlightCommand('/feature', $style);

        return $hi
            ."Thanks for the idea. An admin reviewed your feature request and won't take it forward this time.{$detail}\n\n"
            ."You can send another suggestion anytime with {$cmd}. We appreciate you speaking up.";
    }

    /**
     * Emphasis for short structural labels (WhatsApp *bold* / Telegram HTML <b>).
     * Bodies stay unstyled; only labels like "You asked:" use this.
     *
     * @param  'whatsapp'|'telegram_html'  $style
     */
    public function emphasisLabel(string $label, string $style = 'whatsapp'): string
    {
        $label = trim($label);
        if ($label === '') {
            return '';
        }

        if ($style === 'telegram_html') {
            return '<b>'.$this->escapeTelegramHtml($label).'</b>';
        }

        // WhatsApp (and Telegram clients that auto-parse): single-asterisk bold.
        return '*'.$label.'*';
    }

    /**
     * Escape member-authored body text when the channel uses HTML parse mode.
     *
     * @param  'whatsapp'|'telegram_html'  $style
     */
    public function escapeChannelBody(string $text, string $style = 'whatsapp'): string
    {
        if ($style === 'telegram_html') {
            return $this->escapeTelegramHtml($text);
        }

        return $text;
    }

    /**
     * Telegram HTML parse_mode: escape & < > only (keep apostrophes readable).
     */
    public function escapeTelegramHtml(string $text): string
    {
        return str_replace(
            ['&', '<', '>'],
            ['&amp;', '&lt;', '&gt;'],
            $text,
        );
    }

    /**
     * Map spike channel id → reply emphasis style.
     *
     * @return 'whatsapp'|'telegram_html'
     */
    public function channelEmphasisStyle(?string $channel): string
    {
        $channel = strtolower(trim((string) $channel));
        // Empty channel = legacy Telegram path (older escalation cards).
        if ($channel === '' || $channel === 'telegram_spike') {
            return 'telegram_html';
        }

        return 'whatsapp';
    }

    /**
     * Member ping after an admin approves their /share.
     *
     * @param  'whatsapp'|'telegram_html'  $style
     */
    public function shareApprovedMemberReply(
        ?string $fromName = null,
        ?string $note = null,
        string $style = 'whatsapp',
    ): string {
        $hi = ($fromName !== null && trim($fromName) !== '')
            ? 'Hi '.trim($fromName).",\n\n"
            : '';
        $note = trim((string) $note);
        $detail = $note !== ''
            ? "\n\n".$this->emphasisLabel('Your note:', $style)."\n".$this->escapeChannelBody($note, $style)
            : '';

        return $hi
            ."Good news: an admin approved your note 🙂{$detail}\n\n"
            .'I can use it in answers now. Thanks for helping the community.';
    }

    /**
     * Member ping after an admin declines their /share.
     *
     * @param  'whatsapp'|'telegram_html'  $style
     */
    public function shareDeclinedMemberReply(
        ?string $fromName = null,
        ?string $note = null,
        string $style = 'whatsapp',
    ): string {
        $hi = ($fromName !== null && trim($fromName) !== '')
            ? 'Hi '.trim($fromName).",\n\n"
            : '';
        $note = trim((string) $note);
        $detail = $note !== ''
            ? "\n\n".$this->emphasisLabel('Your note:', $style)."\n".$this->escapeChannelBody($note, $style)
            : '';

        return $hi
            ."An admin reviewed your shared note and didn't publish it this time.{$detail}\n\n"
            .'You can share something else anytime with '.$this->highlightCommand('/share', $style)
            .'. Thanks for trying.';
    }

    /**
     * Short question text for member-facing admin replies (never the RAG envelope).
     */
    public function memberFacingQuestion(string $question): string
    {
        $q = trim($question);
        if ($q === '') {
            return '';
        }

        if (preg_match('/Follow-up:\s*(.+?)(?:\n\nReply formatting:|\z)/us', $q, $m) === 1) {
            return trim($m[1]);
        }

        if (str_starts_with($q, 'The member is following up')
            && preg_match('/Original question:\s*(.+?)(?:\n\n|$)/us', $q, $m) === 1) {
            return trim($m[1]);
        }

        return $q;
    }

    /**
     * Member ping after an admin answers their escalated question.
     *
     * @param  'whatsapp'|'telegram_html'  $style
     * @param  string|null  $adminMentionTag  WhatsApp @digits (or Telegram handle) of the answering admin
     */
    public function askAnsweredMemberReply(
        string $question,
        string $answer,
        ?string $fromName = null,
        string $style = 'whatsapp',
        ?string $adminMentionTag = null,
    ): string {
        // WhatsApp: no English "Hi," — spike inserts a language-neutral @mention.
        // Telegram: optional plain display name (no forced English greeting).
        $hi = '';
        if ($style === 'telegram_html' && $fromName !== null && trim($fromName) !== '') {
            $hi = trim($fromName).",\n\n";
        }
        $question = $this->escapeChannelBody($this->memberFacingQuestion($question), $style);
        $answer = $this->escapeChannelBody(trim($answer), $style);

        $adminLabel = $this->adminUpdateLabel($style, $adminMentionTag);

        return $hi
            .$this->emphasisLabel('You asked:', $style)."\n{$question}\n\n"
            ."{$adminLabel}\n{$answer}";
    }

    /**
     * @param  'whatsapp'|'telegram_html'  $style
     */
    public function adminUpdateLabel(string $style = 'whatsapp', ?string $adminMentionTag = null): string
    {
        $tag = trim((string) $adminMentionTag);
        if ($tag !== '' && ! str_starts_with($tag, '@')) {
            $tag = '@'.$tag;
        }

        if ($tag === '') {
            return $this->emphasisLabel("Here's an update from an admin:", $style);
        }

        // Keep @tag outside bold so WhatsApp can bind a real mention chip.
        if ($style === 'telegram_html') {
            return $this->emphasisLabel("Here's an update from an admin", $style)
                .' ('.$this->escapeTelegramHtml($tag).'):';
        }

        return $this->emphasisLabel("Here's an update from an admin", $style)
            .' ('.$tag.'):';
    }

    /**
     * Other places members can reach Zak (skips the channel they are already on).
     * In group chats, also advertise a private DM link for the current platform.
     *
     * @param  'whatsapp'|'telegram'|'web'|null  $currentChannel
     * @param  'private'|'group'|null  $chatType
     * @return list<array{label: string, url: string}>
     */
    public function channelAccessEntries(
        ?string $currentChannel = null,
        ?string $chatType = null,
        ?string $memberPhoneForWeb = null,
    ): array {
        $entries = [];
        $inGroup = in_array(strtolower((string) $chatType), ['group', 'supergroup'], true);
        $webPhone = $inGroup ? null : $memberPhoneForWeb;

        if ($currentChannel !== 'whatsapp') {
            $entries[] = [
                'label' => (string) config('zak_presence.whatsapp_label', 'WhatsApp'),
                'url' => $this->whatsappPublicUrl(),
            ];
        }

        if ($currentChannel !== 'telegram') {
            $entries[] = [
                'label' => (string) config('zak_presence.telegram_label', 'Telegram'),
                'url' => $this->telegramPublicUrl(),
            ];
        }

        if (filter_var(config('zak_presence.show_web_chat', true), FILTER_VALIDATE_BOOLEAN)) {
            $webLabel = (string) config('zak_presence.web_chat_label', 'Web Chat');
            if ($currentChannel === 'web') {
                $webLabel = $webLabel.' (this page)';
            }
            $entries[] = [
                'label' => $webLabel,
                'url' => $this->webChatInviteUrl($webPhone),
            ];
        }

        // Groups: same "reach me" section; invite a private 1:1 on this platform.
        if ($inGroup) {
            $dmUrl = '';
            if ($currentChannel === 'whatsapp') {
                $dmUrl = $this->whatsappPublicUrl();
            } elseif ($currentChannel === 'telegram') {
                $dmUrl = $this->telegramPublicUrl();
            }
            if ($dmUrl !== '') {
                $entries[] = [
                    'label' => 'Private chat',
                    'url' => $dmUrl,
                ];
            }
        }

        return array_values(array_filter(
            $entries,
            static fn (array $e): bool => trim((string) ($e['label'] ?? '')) !== ''
        ));
    }

    /**
     * Direct private-chat URL for the current channel (config), or empty.
     *
     * @param  'whatsapp'|'telegram'|'web'|null  $currentChannel
     */
    public function privateChatUrl(?string $currentChannel, ?string $memberPhoneForWeb = null): string
    {
        return match ($currentChannel) {
            'whatsapp' => $this->whatsappPublicUrl(preferClickable: true),
            'telegram' => $this->telegramPublicUrl(),
            'web' => $this->webChatInviteUrl($memberPhoneForWeb),
            default => '',
        };
    }

    /**
     * Same-domain web chat link with configured ?k= (and optional member ?p= / ?s=).
     */
    public function webChatInviteUrl(?string $memberPhoneRaw = null): string
    {
        return app(\App\Services\WebChat\WebChatUrlBuilder::class)->inviteUrl($memberPhoneRaw);
    }

    /**
     * English-only offline fallback when personal_help model reply fails (private chat).
     */
    public function personalHelpFallbackReply(): string
    {
        return "Happy to help with that 🙂\n\n"
            ."Break it into a small next step, practice a little every day, "
            ."and ask again if you want a more specific plan. "
            .'For community schedules or links, just ask me as a normal community question.';
    }

    /**
     * Append the real private-chat link after a model-written nudge (any language).
     * Code owns the URL; the model must not invent links.
     *
     * @param  'plain'|'whatsapp'  $style
     * @param  'whatsapp'|'telegram'|'web'|null  $currentChannel
     */
    public function withPrivateChatLink(
        string $reply,
        string $style = 'plain',
        ?string $currentChannel = null,
        ?string $memberPhoneForWeb = null,
    ): string {
        $reply = rtrim($reply);
        $url = $this->privateChatUrl($currentChannel, $memberPhoneForWeb);
        if ($url === '') {
            return $reply;
        }

        $block = $this->formatAccessEntry('Private chat', $url, $style);

        if ($reply === '') {
            return $block;
        }

        return $reply."\n\n".$block;
    }

    /**
     * English-only offline fallback when take_private model reply fails.
     */
    public function takePrivateFallbackReply(string $style = 'plain', ?string $currentChannel = null): string
    {
        $body = "That one is better in a private chat so the group stays focused 🙂\n\n"
            .'Message me privately and ask again there — happy to help with study tips, '
            .'motivation, and personal growth.';

        return $this->withPrivateChatLink($body, $style, $currentChannel);
    }

    /**
     * Label + URL on separate lines so clients keep the full link tappable
     * (inline "Label: https://…" often breaks on phone-number autodetection).
     *
     * @param  'plain'|'whatsapp'  $style
     */
    private function formatAccessEntry(string $label, string $url, string $style): string
    {
        $url = trim($url);
        if ($url === '') {
            return $label;
        }

        if ($style === 'whatsapp') {
            // Prefer api.whatsapp.com so WA does not split wa.me phone digits out of the URL.
            if (str_contains(mb_strtolower($url), 'wa.me/')
                || str_contains(mb_strtolower($url), 'api.whatsapp.com/')
                || str_contains(mb_strtolower($label), 'whatsapp')
                || str_contains(mb_strtolower($label), 'private')) {
                $url = $this->whatsappClickableUrl($url, $this->whatsappOpenChatPrefill());
            }

            return "*{$label}*\n{$url}";
        }

        return "{$label}:\n{$url}";
    }

    /**
     * Prefill text for WhatsApp click-to-chat links (opens composer ready to send).
     * Bare "hi Zak" is treated as a friendly ping in private chat.
     */
    public function whatsappOpenChatPrefill(): string
    {
        return 'hi Zak';
    }

    /**
     * @param  bool  $preferClickable  When true, prefer api.whatsapp.com so phone
     *                                 autodetection does not split wa.me links.
     */
    public function whatsappPublicUrl(bool $preferClickable = false): string
    {
        $url = trim((string) config('zak_presence.whatsapp_url', ''));
        if ($url === '') {
            $botNumber = preg_replace('/\D+/', '', (string) config('whatsapp_web_spike.bot_number', '')) ?? '';
            $url = $botNumber !== '' ? 'https://wa.me/'.$botNumber : '';
        }

        if ($url === '') {
            return '';
        }

        $prefill = $this->whatsappOpenChatPrefill();
        if ($preferClickable) {
            return $this->whatsappClickableUrl($url, $prefill);
        }

        // Keep wa.me when callers want the short form, but still prefill the composer.
        if (preg_match('#wa\.me/(\d+)#i', $url, $m) === 1) {
            return 'https://wa.me/'.$m[1].'?text='.rawurlencode($prefill);
        }

        if (preg_match('#api\.whatsapp\.com/send/?\?phone=(\d+)#i', $url, $m) === 1) {
            return $this->whatsappClickableUrl($url, $prefill);
        }

        return $url;
    }

    /**
     * Convert wa.me / phone links into a form WhatsApp usually keeps fully clickable.
     *
     * @param  string|null  $text  Optional composer prefill (?text=).
     */
    public function whatsappClickableUrl(string $url, ?string $text = null): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $base = null;
        if (preg_match('#(?:wa\.me/|api\.whatsapp\.com/send/?\?phone=)(\d+)#i', $url, $m) === 1) {
            $base = 'https://api.whatsapp.com/send?phone='.$m[1];
        } else {
            $digits = preg_replace('/\D+/', '', $url) ?? '';
            if ($digits !== '' && strlen($digits) >= 8 && ! str_contains($url, '://')) {
                $base = 'https://api.whatsapp.com/send?phone='.$digits;
            }
        }

        if ($base === null) {
            return $url;
        }

        $text = $text !== null ? trim($text) : '';
        if ($text === '') {
            // Keep an existing text= query if the source URL already had one.
            if (preg_match('/[?&]text=([^&]*)/i', $url, $tm) === 1) {
                return $base.'&text='.$tm[1];
            }

            return $base;
        }

        return $base.'&text='.rawurlencode($text);
    }

    public function telegramPublicUrl(): string
    {
        $url = trim((string) config('zak_presence.telegram_url', ''));
        if ($url !== '') {
            return $url;
        }
        $handle = trim((string) config('zak_presence.telegram_handle', ''), " \t\n\r\0\x0B@");

        return $handle !== '' ? 'https://t.me/'.$handle : '';
    }

    /**
     * Other-channel links for intros. Same stacked layout as /help (mobile-friendly).
     *
     * @param  'plain'|'whatsapp'  $style
     * @param  'whatsapp'|'telegram'|'web'|null  $currentChannel  Omit current channel from the list.
     * @param  'private'|'group'|null  $chatType
     */
    public function channelsAccessLine(
        string $style = 'plain',
        ?string $currentChannel = null,
        ?string $chatType = null,
    ): string {
        return $this->channelsAccessBlock($style, $currentChannel, $chatType);
    }

    /**
     * Multi-line "also reach me" block for /help (clearer on mobile).
     *
     * @param  'plain'|'whatsapp'  $style
     * @param  'whatsapp'|'telegram'|'web'|null  $currentChannel
     * @param  'private'|'group'|null  $chatType
     */
    public function channelsAccessBlock(
        string $style = 'plain',
        ?string $currentChannel = null,
        ?string $chatType = null,
        ?string $memberPhoneForWeb = null,
    ): string {
        $parts = [];
        foreach ($this->channelAccessEntries($currentChannel, $chatType, $memberPhoneForWeb) as $entry) {
            $parts[] = $this->formatAccessEntry(
                (string) ($entry['label'] ?? ''),
                (string) ($entry['url'] ?? ''),
                $style,
            );
        }
        if ($parts === []) {
            return '';
        }

        if ($style === 'whatsapp') {
            return "*Also reach me on*\n\n".implode("\n\n", $parts);
        }

        $lines = array_map(static fn (string $p): string => '• '.$p, $parts);

        return "Also reach me on:\n\n".implode("\n\n", $lines);
    }

    /**
     * Member-facing /help and /start (no admin commands).
     * WhatsApp + Telegram share the same content; only emphasis/wrapping differs.
     *
     * @param  'plain'|'whatsapp'  $style
     * @param  'whatsapp'|'telegram'|'web'|null  $currentChannel
     * @param  'private'|'group'|null  $chatType
     */
    public function memberHelpText(
        string $style = 'plain',
        ?string $currentChannel = null,
        ?string $chatType = null,
        ?string $memberPhoneForWeb = null,
    ): string {
        $channels = $this->channelsAccessBlock($style, $currentChannel, $chatType, $memberPhoneForWeb);
        $scope = $this->friendlyScopeSummary(null);
        $bot = $this->botDisplayName();
        $examples = $this->formatHelpExampleLines($this->rotatingHelpExamples());

        if ($style === 'whatsapp') {
            return "*Hi - I'm {$bot}* 👋\n\n"
                ."I help with {$scope}.\n"
                .$this->anyLanguageHint()."\n\n"
                ."*What I can do*\n"
                ."• Answer questions from community knowledge - schedules, links, people, updates 💬\n"
                ."• Catch you up on what you missed, with sources when I have them 🔎\n"
                ."• Take a tip via /share (an admin reviews it before I use it) ✍️\n"
                ."• Take a /feature request or improvement idea for admin review 💡\n"
                ."• List published program files with /assets (forms, slides, handbooks) 📂\n"
                ."• Hear voice notes and read photos, then reply in your language 🎧📷\n\n"
                ."*If I don't have an answer yet*\n"
                ."I'll pass it along and notify you once one is available - "
                ."no need to keep asking 🙂\n\n"
                ."*Try asking*\n"
                .$examples."\n\n"
                .($channels !== '' ? $channels."\n\n" : '')
                ."*Quick commands*\n"
                .$this->formatMemberCommandHelp('whatsapp')."\n\n"
                ."Or just type in plain language - no command needed.\n"
                .'In group chats, '.$this->emphasisLabel('@mention', 'whatsapp')
                .' me or '.$this->emphasisLabel('reply', 'whatsapp')
                .' to my message so I know you mean me.';
        }

        return "Hi - I'm {$bot} 👋\n\n"
            ."I help with {$scope}.\n"
            .$this->anyLanguageHint()."\n\n"
            ."What I can do:\n"
            ."• Answer questions from community knowledge - schedules, links, people, updates 💬\n"
            ."• Catch you up on what you missed, with sources when I have them 🔎\n"
            ."• Take a tip via /share (an admin reviews it before I use it) ✍️\n"
            ."• Take a /feature request or improvement idea for admin review 💡\n"
            ."• List published program files with /assets (forms, slides, handbooks) 📂\n"
            ."• Hear voice notes and read photos, then reply in your language 🎧📷\n\n"
            ."If I don't have an answer yet:\n"
            ."I'll pass it along and notify you once one is available - "
            ."no need to keep asking 🙂\n\n"
            ."Try asking:\n"
            .$examples."\n\n"
            .($channels !== '' ? $channels."\n\n" : '')
            ."Quick commands:\n"
            .$this->formatMemberCommandHelp('plain')."\n\n"
            ."Or just type in plain language - no command needed.\n"
            .'In group chats, @mention me or reply to my message so I know you mean me.';
    }

    /**
     * Rotating example asks so /help and /start feel fresh (English structural copy only).
     *
     * @return list<array{0: string, 1: string}>
     */
    public function rotatingHelpExamples(): array
    {
        /** @var list<list<array{0: string, 1: string}>> $sets */
        $sets = [
            [
                ['When is the next session?', '📅'],
                ["What's the meeting link?", '🔗'],
                ["Share today's updates", '📰'],
                ['Who should I talk to about X?', '👤'],
            ],
            [
                ['Any deadlines this week?', '⏰'],
                ['Where are the session recordings?', '🎬'],
                ['What did I miss yesterday?', '📝'],
                ['Is there a form I still need to fill?', '📋'],
            ],
            [
                ['When does onboarding start?', '🚀'],
                ['Send me the join link for today', '🔗'],
                ["What's new in the community?", '✨'],
                ['Who is the mentor for my cohort?', '👤'],
            ],
            [
                ['Is there a meeting tomorrow?', '📆'],
                ['Do you have the Drive folder?', '📁'],
                ['Summarise this week\'s updates', '🗞️'],
                ['Who can help with my application?', '🤝'],
            ],
        ];

        $index = random_int(0, count($sets) - 1);

        return $sets[$index];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $examples
     */
    public function formatHelpExampleLines(array $examples): string
    {
        $lines = [];
        foreach ($examples as $row) {
            $text = (string) ($row[0] ?? '');
            $emoji = (string) ($row[1] ?? '');
            if ($text === '') {
                continue;
            }
            $lines[] = $emoji !== '' ? "• {$text} {$emoji}" : "• {$text}";
        }

        return implode("\n", $lines);
    }

    /**
     * Rotating sample invocations for member /commands (English structural UX only).
     *
     * @return array{ask: string, share: string, feature: string, assets: string}
     */
    public function rotatingMemberCommandExamples(): array
    {
        /** @var list<array{ask: string, share: string, feature: string, assets: string}> $sets */
        $sets = [
            [
                'ask' => '/ask When is the next session?',
                'share' => '/share Clinic moved to 3pm tomorrow',
                'feature' => '/feature Remind me a day before deadlines',
                'assets' => '/assets form',
            ],
            [
                'ask' => '/ask Where are the session recordings?',
                'share' => '/share Join link for Friday is in the Drive folder',
                'feature' => '/feature Add a weekly summary every Monday',
                'assets' => '/assets slides',
            ],
            [
                'ask' => '/ask Who is the mentor for my cohort?',
                'share' => '/share Onboarding starts Monday at 10am',
                'feature' => '/feature Let me save favourite links',
                'assets' => '/assets',
            ],
            [
                'ask' => '/ask Is there a form I still need to fill?',
                'share' => '/share Demo day is next Thursday',
                'feature' => '/feature Support voice replies in groups',
                'assets' => '/assets handbook',
            ],
        ];

        return $sets[random_int(0, count($sets) - 1)];
    }

    /**
     * Rotating sample invocations for admin /commands.
     *
     * @return array{import: string, asset: string, publish: string, knowledge: string, features: string, approve: string, decline: string, reply: string}
     */
    public function rotatingAdminCommandExamples(): array
    {
        /** @var list<array{import: string, asset: string, publish: string, knowledge: string, features: string, approve: string, decline: string, reply: string}> $sets */
        $sets = [
            [
                'import' => '/import [paste the WhatsApp/Telegram export text]',
                'asset' => '/asset handbook UniPods Handbook https://drive.google.com/file/d/YOUR_FILE_ID/view',
                'publish' => '/publish ABC123',
                'knowledge' => '/knowledge',
                'features' => '/features open',
                'approve' => '/approve H7G74Y',
                'decline' => '/decline H7G74Y',
                'reply' => '/reply H7G74Y The session is at 4pm',
                'logins' => '/logins',
            ],
            [
                'import' => '/import [paste chat export here]',
                'asset' => '/asset form Signup form https://drive.google.com/file/d/YOUR_FILE_ID/view',
                'publish' => '/publish latest',
                'knowledge' => '/knowledge',
                'features' => '/features',
                'approve' => '/approve W7X1YT',
                'decline' => '/decline W7X1YT',
                'reply' => '/reply W7X1YT Yes - open until Friday',
                'logins' => '/logins',
            ],
            [
                'import' => '/import [full export dump]',
                'asset' => '/asset slides Week 1 deck https://drive.google.com/file/d/YOUR_FILE_ID/view',
                'publish' => '/publish',
                'knowledge' => '/knowledge',
                'features' => '/features decided',
                'approve' => '/approve 9D5GWS',
                'decline' => '/decline 9D5GWS',
                'reply' => '/reply 9D5GWS Mentors are listed in the Drive folder',
                'logins' => '/logins',
            ],
        ];

        return $sets[random_int(0, count($sets) - 1)];
    }

    /**
     * @param  'plain'|'whatsapp'  $style
     */
    public function formatMemberCommandHelp(string $style = 'plain'): string
    {
        $ex = $this->rotatingMemberCommandExamples();

        return $this->formatCommandWithExample('/ask', 'ask about schedules, links, or updates', $ex['ask'], $style)."\n"
            .$this->formatCommandWithExample('/share', 'share a tip with the community (admin reviews first)', $ex['share'], $style)."\n"
            .$this->formatCommandWithExample('/feature', 'request a feature or suggest an improvement', $ex['feature'], $style)."\n"
            .$this->formatCommandWithExample('/assets', 'list published program files (forms, slides, handbooks)', $ex['assets'], $style)."\n"
            .$this->formatCommandWithExample('/help', 'show this guide again', null, $style);
    }

    /**
     * @param  'plain'|'whatsapp'  $style
     */
    private function formatCommandWithExample(
        string $command,
        string $blurb,
        ?string $example,
        string $style,
    ): string {
        $cmd = $this->highlightCommand($command, $style === 'whatsapp' ? 'whatsapp' : 'plain');
        if ($style === 'whatsapp') {
            $line = "{$cmd}  {$blurb}";
            if ($example !== null && $example !== '') {
                $exCmd = $this->highlightCommand(
                    (string) (preg_split('/\s+/', $example, 2)[0] ?? $command),
                    'whatsapp',
                );
                $exRest = trim((string) preg_replace('/^\/\S+\s*/u', '', $example));
                $line .= $exRest !== ''
                    ? "\n  _e.g._ {$exCmd} {$exRest}"
                    : "\n  _e.g._ {$exCmd}";
            }

            return $line;
        }

        $line = "{$cmd} - {$blurb}";
        if ($example !== null && $example !== '') {
            $line .= "\n  e.g. {$example}";
        }

        return $line;
    }

    /**
     * Extra /help block for admins only (sensitive commands stay out of member view).
     *
     * @param  'plain'|'whatsapp'  $style
     */
    public function adminHelpAppendix(string $style = 'plain'): string
    {
        $ex = $this->rotatingAdminCommandExamples();
        $wa = $style === 'whatsapp';
        $heading = $wa ? "\n\n*Admin*\n" : "\n\nAdmin:\n";

        return $heading
            .$this->formatCommandWithExample('/import', 'paste a chat export to create a draft', $ex['import'], $style)."\n"
            .$this->formatCommandWithExample('/publish', 'make a draft live for members', $ex['publish'], $style)."\n"
            .$this->formatCommandWithExample('/unpublish', 'archive a published doc or Drive file', $ex['unpublish'] ?? '/unpublish ABC123', $style)."\n"
            .$this->formatCommandWithExample('/knowledge', 'see drafts and published knowledge', $ex['knowledge'], $style)."\n"
            .$this->formatCommandWithExample('/asset', 'add a Drive file link for members', $ex['asset'], $style)."\n"
            .$this->formatCommandWithExample('/features', 'list open or decided feature requests', $ex['features'], $style)."\n"
            .$this->formatCommandWithExample('/approve', 'approve a share or feature (Request ID)', $ex['approve'], $style)."\n"
            .$this->formatCommandWithExample('/decline', 'decline a share or feature (Request ID)', $ex['decline'], $style)."\n"
            .$this->formatCommandWithExample('/reply', 'answer an escalated member question', $ex['reply'], $style)."\n"
            .$this->formatCommandWithExample('/logins', 'view admin phones and web login passwords', $ex['logins'] ?? '/logins', $style);
    }

    /**
     * Full /help for the caller: members get the public card; admins get extras.
     *
     * @param  'plain'|'whatsapp'  $style
     * @param  'whatsapp'|'telegram'|'web'|null  $currentChannel
     * @param  'private'|'group'|null  $chatType
     */
    public function helpTextFor(
        string $style = 'plain',
        ?string $currentChannel = null,
        bool $isAdmin = false,
        ?string $chatType = null,
        ?string $memberPhoneForWeb = null,
    ): string {
        $body = $this->memberHelpText($style, $currentChannel, $chatType, $memberPhoneForWeb);

        return $isAdmin ? $body.$this->adminHelpAppendix($style) : $body;
    }

    /**
     * Member asking where else to reach Zak (Telegram / WhatsApp / web chat).
     */
    public function isChannelPresenceAsk(string $text): bool
    {
        $q = mb_strtolower(trim($text));
        $q = str_replace(["\u{2019}", "\u{2018}", '`'], "'", $q);
        $q = preg_replace('/\s+/u', ' ', $q) ?? $q;
        $q = rtrim($q, " \t\n\r\0\x0B.!？?~");

        if ($q === '') {
            return false;
        }

        if (preg_match(
            '/\b(telegram|whatsapp|wa\.me|t\.me|web\s*chat|webchat|private\s*chat|dm)\b/u',
            $q
        ) !== 1) {
            return false;
        }

        // Avoid treating community knowledge asks about those platforms as presence.
        if (preg_match(
            '/\b(schedule|session|meeting|recording|deadline|module|cohort|clinic|announcement)\b/u',
            $q
        ) === 1) {
            return false;
        }

        return preg_match(
            '/\b(link|url|handle|username|number|invite|reach|find|contact|message|chat|dm|available|join|open|share|send|give|have|your|you|what|where|how|also)\b/u',
            $q
        ) === 1
            || preg_match('/^(telegram|whatsapp|web\s*chat|webchat)\b/u', $q) === 1
            || mb_strlen($q) <= 48;
    }

    /**
     * Clickable channel links for the platforms the member is not already on.
     *
     * @param  'plain'|'whatsapp'  $style
     * @param  'whatsapp'|'telegram'|'web'|null  $currentChannel
     * @param  'private'|'group'|null  $chatType
     */
    public function channelPresenceReply(
        string $style = 'plain',
        ?string $currentChannel = null,
        ?string $chatType = null,
        ?string $memberPhoneForWeb = null,
    ): string {
        $block = $this->channelsAccessBlock($style, $currentChannel, $chatType, $memberPhoneForWeb);
        if ($block === '') {
            return "You're already chatting with me here 🙂 Send /help if you want a quick tour.";
        }

        return "Yes - you can also reach me here 🙂\n\n".$block;
    }

    /**
     * Compact intro for greetings / who-are-you (keeps UX short).
     *
     * @param  'plain'|'whatsapp'  $style
     * @param  'whatsapp'|'telegram'|'web'|null  $currentChannel
     * @param  'private'|'group'|null  $chatType
     */
    public function shortIntro(
        string $style = 'plain',
        ?string $currentChannel = null,
        ?string $chatType = null,
        ?string $memberPhoneForWeb = null,
    ): string {
        // Stacked label + URL (same as /help) — one-line "Also on: a · b · c" wraps badly on mobile.
        $channels = $this->channelsAccessBlock($style, $currentChannel, $chatType, $memberPhoneForWeb);
        $line = "I help with community schedules, updates, and what's been shared. "
            .$this->anyLanguageHint()
            ."\nYou can send a voice note or photo. I listen, read, and reply in your language.";

        if ($channels !== '') {
            $line .= "\n\n".$channels;
        }

        return $line;
    }

    /**
     * Meta questions about what Zak is or can do (including voice notes / photos).
     */
    public function isZakCapabilityAsk(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') {
            return false;
        }

        if (preg_match('/^(who are you|what (?:can|do) you do|what are you)\b/u', $t) === 1) {
            return true;
        }

        if (preg_match('/\b(voice note|voice message|voice notes|audio message|send voice|photo|photos|image|images|picture|pictures)\b/u', $t) === 1
            && preg_match('/\b(can you|do you|are you able|support|listen|hear|accept|handle|read)\b/u', $t) === 1) {
            return true;
        }

        return preg_match('/\bwhat (?:can|do) you (?:do|support|handle)\b/u', $t) === 1;
    }

    /**
     * Accurate capability summary for "what can you do?" / voice-note questions.
     *
     * @param  'plain'|'whatsapp'  $style
     * @param  'whatsapp'|'telegram'|'web'|null  $currentChannel
     * @param  'private'|'group'|null  $chatType
     */
    public function zakCapabilityReply(
        string $style = 'plain',
        ?string $currentChannel = null,
        ?string $chatType = null,
        ?string $memberPhoneForWeb = null,
    ): string {
        $scope = $this->friendlyScopeSummary(null);
        $bot = $this->botDisplayName();
        $channels = $this->channelsAccessBlock($style, $currentChannel, $chatType, $memberPhoneForWeb);

        if ($style === 'whatsapp') {
            $body = "*I'm {$bot}* 🙂\n\n"
                ."Here's what I can do:\n"
                ."• Answer from community knowledge — {$scope} 💬\n"
                ."• Listen to voice notes and read photos on Telegram, WhatsApp, and web, then reply in your language 🎧📷\n"
                ."• Catch you up on what you missed when I have sources 🔎\n"
                ."• Take tips via ".$this->highlightCommand('/share', 'whatsapp')
                .' and ideas via '.$this->highlightCommand('/feature', 'whatsapp')." ✍️\n"
                ."• If I don't know yet, I'll say so and follow up when I can 🙂\n\n"
                .$this->anyLanguageHint();

            if ($channels !== '') {
                $body .= "\n\n".$channels;
            }

            return $body;
        }

        $body = "I'm {$bot} 🙂\n\n"
            ."Here's what I can do:\n"
            ."• Answer from community knowledge — {$scope}\n"
            ."• Listen to voice notes and read photos on Telegram, WhatsApp, and web, then reply in your language\n"
            ."• Catch you up on what you missed when I have sources\n"
            ."• Take tips via /share and ideas via /feature (admin review)\n"
            ."• If I don't know yet, I'll say so and follow up when I can\n\n"
            .$this->anyLanguageHint();

        if ($channels !== '') {
            $body .= "\n\n".$channels;
        }

        return $body;
    }

    /**
     * Short plain-language scope line for members (never dump the raw admin blurb).
     */
    public function friendlyScopeSummary(?string $communityDescription = null): string
    {
        // Full description is for routing/overlap only. Chat copy stays short.
        if ($this->normalizedScope($communityDescription) === '') {
            return 'schedules, updates, links, and what has been shared in the group';
        }

        return 'schedules, sessions, updates, links, and what has been shared in the group';
    }

    /**
     * @return list<string>
     */
    public function scopeTokens(?string $communityDescription): array
    {
        $scope = $this->normalizedScope($communityDescription);
        if ($scope === '') {
            return [];
        }

        return $this->contentTokens($scope);
    }

    private function overlapsCommunityScope(string $question, ?string $communityDescription): bool
    {
        $scopeTokens = $this->scopeTokens($communityDescription);
        if ($scopeTokens === []) {
            return false;
        }

        $questionTokens = $this->contentTokens($question);
        if ($questionTokens === []) {
            return false;
        }

        $overlap = array_intersect($scopeTokens, $questionTokens);

        return count($overlap) >= 1;
    }

    /**
     * @return list<string>
     */
    private function contentTokens(string $text): array
    {
        $text = mb_strtolower($text);
        $text = str_replace(["\u{2019}", "\u{2018}", '`'], "'", $text);
        preg_match_all('/[a-z0-9]{3,}/u', $text, $matches);
        $tokens = $matches[0] ?? [];
        $stop = [
            'the', 'and', 'for', 'with', 'that', 'this', 'from', 'your', 'you', 'are', 'was',
            'were', 'have', 'has', 'had', 'will', 'can', 'could', 'would', 'should', 'about',
            'into', 'over', 'under', 'here', 'there', 'what', 'when', 'where', 'which', 'who',
            'how', 'why', 'any', 'all', 'our', 'their', 'them', 'they', 'been', 'being',
            'also', 'just', 'more', 'some', 'such', 'than', 'then', 'too', 'very', 'only',
            'happy', 'help', 'ask', 'please', 'send', 'give', 'find', 'get', 'list',
        ];

        $kept = [];
        foreach ($tokens as $token) {
            if (in_array($token, $stop, true)) {
                continue;
            }
            $kept[$token] = true;
        }

        return array_keys($kept);
    }

    private function normalizedScope(?string $communityDescription): string
    {
        $scope = trim((string) $communityDescription);
        $scope = preg_replace('/\s+/u', ' ', $scope) ?? $scope;

        return $scope;
    }

    public function conversationalReply(
        string $text,
        string $style = 'plain',
        ?string $currentChannel = null,
        ?string $chatType = null,
        ?string $memberPhoneForWeb = null,
    ): string {
        $normalized = mb_strtolower(trim($text));
        $stripped = rtrim($normalized, " \t\n\r\0\x0B.!？?~");
        $channel = $currentChannel ?? ($style === 'whatsapp' ? 'whatsapp' : null);

        if ($this->isChannelPresenceAsk($stripped)) {
            return $this->channelPresenceReply($style, $channel, $chatType, $memberPhoneForWeb);
        }

        if (preg_match('/^(hi|hello|hey|howdy|yo|hiya|hola|bonjour|salut|good morning|good afternoon|good evening|morning|evening)\b/u', $stripped) === 1) {
            return "Good to hear from you 🙂\n\n"
                .$this->shortIntro($style, $channel, $chatType, $memberPhoneForWeb)."\n\n"
                ."What's on your mind?";
        }

        if (preg_match('/^(thanks|thank you|thx|ty|merci)\b/u', $stripped) === 1) {
            return "You're welcome 🙂 Anytime.";
        }

        if (preg_match('/^(bye|goodbye|good bye|see you|see ya|later|take care|ciao)\b/u', $stripped) === 1) {
            return "Take care 👋 Message me whenever a question comes up.";
        }

        if (preg_match('/^(how are you|how\'?s it going|how are things|what\'?s up)\b/u', $stripped) === 1) {
            return "Doing well, thanks 🙂 What do you need help with?";
        }

        if ($this->isZakCapabilityAsk($text)) {
            return $this->zakCapabilityReply($style, $channel, $chatType, $memberPhoneForWeb);
        }

        if (preg_match('/\b(not friendly|unfriendly|rude|mean|cold|unhelpful)\b/u', $stripped) === 1
            || preg_match('/^why (?:are|aren\'t|are not) you\b/u', $stripped) === 1) {
            return "Sorry if I came across that way 🙂 I'm here to help.\n\n"
                .$this->shortIntro($style, $channel, $chatType, $memberPhoneForWeb);
        }

        if (in_array($stripped, ['ok', 'okay', 'k', 'cool', 'great', 'nice', 'perfect', 'got it', 'alright', 'sure', 'yes', 'yep', 'yeah'], true)) {
            return "Sounds good 🙂 I'm here if another question comes up.";
        }

        if (in_array($stripped, ['no', 'nope'], true)) {
            return "No worries 🙂 Ask whenever you need something.";
        }

        return "What's on your mind? 🙂";
    }

    /**
     * Enrich a knowledge question with recent turns so follow-ups keep context.
     *
     * @param  list<array{role: string, text: string}>  $turns
     */
    public function buildKnowledgeQuery(string $currentText, array $turns): string
    {
        // Follow-up envelopes already include prior Q+A — re-wrapping with recent
        // turns duplicates the answer and can timeout the AI call ("hit a snag").
        if (str_starts_with(trim($currentText), 'The member is following up')) {
            return $currentText;
        }

        $recent = array_slice($turns, -6);
        if ($recent === []) {
            return $currentText;
        }

        $lines = [];
        foreach ($recent as $turn) {
            $role = ($turn['role'] ?? '') === 'assistant' ? 'Assistant' : 'User';
            $text = trim((string) ($turn['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            // Cap each turn so long bot answers do not blow the retrieval query.
            if (mb_strlen($text) > 400) {
                $text = mb_substr($text, 0, 400).'…';
            }
            $lines[] = "{$role}: {$text}";
        }

        if ($lines === []) {
            return $currentText;
        }

        return "Recent chat (use only to resolve references like \"it\" / \"that\"; "
            ."answer ONLY the current question from what the community has shared; "
            ."do not reuse the previous answer pattern unless the current question asks for the same thing; "
            ."REPLY LANGUAGE: match the Current question only "
            ."(English Current question → English reply; French → French; "
            ."ignore earlier Assistant turns in other languages):\n"
            .implode("\n", $lines)
            ."\n\nCurrent question: {$currentText}";
    }
}
