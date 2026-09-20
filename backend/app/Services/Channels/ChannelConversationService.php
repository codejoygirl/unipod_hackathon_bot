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

    private const MAX_TURNS = 12;

    private const SESSION_TTL_HOURS = 24;

    public function sessionKey(string $channel, string $externalUserId): string
    {
        return $channel.'_session:'.sha1($externalUserId);
    }

    /**
     * @return list<array{role: string, text: string}>
     */
    public function turns(string $channel, string $externalUserId): array
    {
        $payload = Cache::get($this->sessionKey($channel, $externalUserId));
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
    ): void {
        $key = $this->sessionKey($channel, $externalUserId);
        $turns = $this->turns($channel, $externalUserId);
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

        if ($this->normalizedScope($communityDescription) !== '') {
            return self::INTENT_OUT_OF_SCOPE;
        }

        return self::INTENT_KNOWLEDGE;
    }

    public function clarificationReply(): string
    {
        return "Happy to help. Could you say a bit more about what you need "
            .'from the community (a person, a session, a link, a deadline)?';
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
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;

        return trim($t);
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

        return preg_match('/^who(?:\'?s|\s+is|\s+are)?\s+[a-z0-9_]{2,40}\b/u', $q) === 1
            || preg_match('/^(?:tell me about|what about)\s+[a-z0-9_]{2,40}\b/u', $q) === 1;
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

        return [
            'intent' => $this->classifyIntent($trimmed, $communityDescription),
            'query' => $trimmed,
        ];
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
            if ($this->isPurelySocial($candidate) || $this->isClearlyOutOfScope($candidate)) {
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

        $greetings = [
            'hi', 'hello', 'hey', 'howdy', 'yo', 'hiya', 'hola', 'bonjour', 'salut',
            'good morning', 'good afternoon', 'good evening', 'morning', 'evening',
        ];
        if (in_array($stripped, $greetings, true)) {
            return true;
        }
        if (preg_match('/^(hi|hello|hey|bonjour|salut)\b/u', $stripped) === 1
            && mb_strlen($stripped) <= 24) {
            return true;
        }

        $thanks = ['thanks', 'thank you', 'thx', 'ty', 'merci', 'thank you so much', 'thanks a lot'];
        if (in_array($stripped, $thanks, true)
            || preg_match('/^(thanks|thank you|merci)\b/u', $stripped) === 1) {
            return true;
        }

        $goodbyes = ['bye', 'goodbye', 'good bye', 'see you', 'see ya', 'later', 'take care', 'ciao'];
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

        // Language ability / preference directed at Zak.
        if (preg_match(
            '/\b(?:speak|parlez|parles|hablas|habla)\b.+\b(?:french|français|francais|spanish|español|english|anglais)\b/u',
            $stripped
        ) === 1
            || preg_match(
                '/\b(?:french|français|francais|spanish|español)\b.+\b(?:speak|parlez|parles)\b/u',
                $stripped
            ) === 1
            || preg_match('/^(?:parlez-vous|hablas|do you speak)\b/u', $stripped) === 1) {
            return true;
        }

        if (preg_match(
            '/\b(not friendly|unfriendly|rude|mean|cold|unhelpful)\b/u',
            $stripped
        ) === 1
            || preg_match('/^why (?:are|aren\'t|are not) you\b/u', $stripped) === 1) {
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
            '/^(?:tell me a joke|write (?:me )?(?:a )?poem|weather (?:in|for|today)|what(?:\'?s| is) the weather|capital of)\b/iu',
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
        if ($this->isClearlyOutOfScope($text)) {
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

        // Explicit ask for shared links / files / notes.
        if (preg_match(
            '/\b(send|share|give|find|get|list|envoyez|envoie|envoyez-moi|envoie-moi)\b.+\b(link|links|lien|liens|recording|recordings|enregistrement|enregistrements|notes|doc|docs|file|files|url|urls)\b/u',
            $q
        ) === 1) {
            return true;
        }

        return false;
    }

    public function outOfScopeReply(?string $communityName = null, ?string $communityDescription = null): string
    {
        $label = trim((string) $communityName);
        $where = $label !== '' ? $label : 'this community';
        $focus = $this->friendlyScopeSummary($communityDescription);

        return "Sorry, I can't help with that one.\n\n"
            ."In {$where}, I can help with {$focus}.\n\n"
            ."Ask me anything about the community anytime. "
            ."If I don't have an answer right now, I'll say so and come back once I do.";
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

    public function conversationalReply(string $text): string
    {
        $normalized = mb_strtolower(trim($text));
        $stripped = rtrim($normalized, " \t\n\r\0\x0B.!？?~");

        if (preg_match('/^(hi|hello|hey|howdy|yo|hiya|hola|bonjour|salut|good morning|good afternoon|good evening|morning|evening)\b/u', $stripped) === 1) {
            return "Good to hear from you.\n\n"
                ."Ask me anything about this community. That often includes "
                ."deadlines, schedules, announcements, meeting notes, and links, "
                ."and I'm happy to help with whatever else has been shared here.\n\n"
                ."If I can't answer something right now, I'll let you know "
                ."and come back once I have an answer.\n\n"
                ."What's on your mind?";
        }

        if (preg_match('/^(thanks|thank you|thx|ty|merci)\b/u', $stripped) === 1) {
            return "You're welcome. Anytime.";
        }

        if (preg_match('/^(bye|goodbye|good bye|see you|see ya|later|take care|ciao)\b/u', $stripped) === 1) {
            return "Take care. Message me whenever a question comes up.";
        }

        if (preg_match('/^(how are you|how\'?s it going|how are things|what\'?s up)\b/u', $stripped) === 1) {
            return "Doing well, thanks. What do you need help with?";
        }

        if (preg_match('/^(who are you|what (?:can|do) you do|help)\b/u', $stripped) === 1) {
            return "I'm Zak.\n\n"
                ."Ask me anything about this community. That often includes "
                ."deadlines, schedules, announcements, meeting notes, and links, "
                ."and I'm happy to help with whatever else has been shared here.\n\n"
                ."If I can't answer something right now, I'll let you know "
                ."and come back once I have an answer.\n\n"
                ."Just ask in plain language. You can also /share something if it should be kept.";
        }

        if (preg_match('/\b(not friendly|unfriendly|rude|mean|cold|unhelpful)\b/u', $stripped) === 1
            || preg_match('/^why (?:are|aren\'t|are not) you\b/u', $stripped) === 1) {
            return "Sorry if I came across that way. I'm here to help.\n\n"
                ."Ask me anything about this community: schedules, updates, links, "
                ."and what has been shared in the group.\n\n"
                ."If I don't have an answer right now, I'll say so and come back once I do.";
        }

        if (in_array($stripped, ['ok', 'okay', 'k', 'cool', 'great', 'nice', 'perfect', 'got it', 'alright', 'sure', 'yes', 'yep', 'yeah'], true)) {
            return "Sounds good. I'm here if another question comes up.";
        }

        if (in_array($stripped, ['no', 'nope'], true)) {
            return "No worries. Ask whenever you need something.";
        }

        return "Hey. What's on your mind?";
    }

    /**
     * Enrich a knowledge question with recent turns so follow-ups keep context.
     *
     * @param  list<array{role: string, text: string}>  $turns
     */
    public function buildKnowledgeQuery(string $currentText, array $turns): string
    {
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
            $lines[] = "{$role}: {$text}";
        }

        if ($lines === []) {
            return $currentText;
        }

        return "Recent chat (use only to resolve references like \"it\" / \"that\"; "
            ."answer ONLY the current question from what the community has shared; "
            ."do not reuse the previous answer pattern unless the current question asks for the same thing):\n"
            .implode("\n", $lines)
            ."\n\nCurrent question: {$currentText}";
    }
}
