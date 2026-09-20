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
    ) {}

    public function channelName(): string
    {
        return 'telegram_spike';
    }

    public function handleInbound(InboundMessage $message): ?string
    {
        if ($message->text === '') {
            return null;
        }

        $adminReply = $this->tryHandleAdminEscalationReply($message);
        if ($adminReply !== null) {
            return $adminReply;
        }

        $upper = strtoupper($message->text);

        if (str_starts_with($upper, 'JOIN-') || str_starts_with($upper, '/JOIN')) {
            return $this->handleJoin($message);
        }

        if (str_starts_with($upper, 'SHARE')
            || str_starts_with($upper, '/SHARE')
            || str_starts_with($upper, 'EXPORT')
            || str_starts_with($upper, '/EXPORT')) {
            return $this->handleShareStub($message);
        }

        $community = $this->resolveLinkedCommunity($message);
        $scope = $community?->description;
        $priorTurns = $this->conversation->turns($this->channelName(), $message->externalUserId);
        $resolved = $this->conversation->resolveInbound($message->text, $priorTurns, $scope);
        $resolved = $this->applyModelIntentIfNeeded($resolved, $community);
        $intent = $resolved['intent'];
        $effectiveQuery = $resolved['query'];

        if ($intent === 'clarify') {
            $reply = $this->modelAssistedReply($message->text, 'social', $community);
            if ($reply === '' || str_contains(mb_strtolower($reply), 'thanks for joining')) {
                $reply = $this->conversation->clarificationReply();
            }
            $this->conversation->remember($this->channelName(), $message->externalUserId, 'user', $message->text);
            $this->conversation->remember($this->channelName(), $message->externalUserId, 'assistant', $reply);

            return $reply;
        }

        if ($intent === ChannelConversationService::INTENT_CONVERSATIONAL) {
            return $this->handleConversational($message, $community);
        }

        if ($intent === ChannelConversationService::INTENT_OUT_OF_SCOPE) {
            $reply = $this->modelAssistedReply(
                $message->text,
                'out_of_scope',
                $community,
            );
            $this->conversation->remember($this->channelName(), $message->externalUserId, 'user', $message->text);
            $this->conversation->remember($this->channelName(), $message->externalUserId, 'assistant', $reply);

            return $reply;
        }

        return $this->handleAsk(
            $message,
            $effectiveQuery,
            linkMode: (string) ($resolved['link_mode'] ?? 'none'),
        );
    }

    /**
     * Hybrid cascade: rules for social + hard OOS; model for everything else.
     *
     * @param  array{intent: string, query: string, link_mode?: string}  $resolved
     * @return array{intent: string, query: string, link_mode: string}
     */
    private function applyModelIntentIfNeeded(array $resolved, ?Community $community): array
    {
        $resolved['link_mode'] = (string) ($resolved['link_mode'] ?? 'none');
        $query = (string) ($resolved['query'] ?? '');
        if (! $this->conversation->needsModelRouting($query)) {
            return $resolved;
        }

        $ctx = $this->communityAiContext($community);
        $classified = $this->aiClient->classifyConversationIntent(
            message: $query,
            communityName: $ctx['name'],
            communityScope: $ctx['scope'],
        );

        if ($classified === null) {
            return $resolved;
        }

        $modelIntent = (string) ($classified['intent'] ?? '');
        $linkMode = (string) ($classified['link_mode'] ?? 'none');

        if ($modelIntent === 'clarify') {
            $resolved['intent'] = 'clarify';
            $resolved['link_mode'] = 'none';

            return $resolved;
        }

        $resolved['intent'] = $modelIntent;
        $resolved['link_mode'] = $linkMode;

        return $resolved;
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

        $replyTo = trim((string) ($message->raw['reply_to_message_id'] ?? ''));
        if ($replyTo === '') {
            return null;
        }

        $result = $this->escalationNotifier->forwardAdminReply($replyTo, $message->text);

        return (string) ($result['reply'] ?? 'Done.');
    }

    private function handleConversational(InboundMessage $message, ?Community $community = null): string
    {
        $reply = $this->modelAssistedReply($message->text, 'social', $community);
        $this->conversation->remember($this->channelName(), $message->externalUserId, 'user', $message->text);
        $this->conversation->remember($this->channelName(), $message->externalUserId, 'assistant', $reply);

        return $reply;
    }

    private function modelAssistedReply(string $text, string $mode, ?Community $community): string
    {
        $ctx = $this->communityAiContext($community);
        $reply = $this->aiClient->conversationalReply(
            message: $text,
            mode: $mode,
            communityName: $ctx['name'],
            communityScope: $ctx['scope'],
        );

        if ($reply !== '') {
            return $reply;
        }

        if ($mode === 'out_of_scope') {
            return $this->conversation->outOfScopeReply($community?->name, $community?->description);
        }

        return $this->conversation->conversationalReply($text);
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
        $this->conversation->remember($this->channelName(), $message->externalUserId, 'user', $message->text);
        $this->conversation->remember($this->channelName(), $message->externalUserId, 'assistant', $reply);

        return $reply;
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
            : (string) config('telegram_spike.default_community_id');

        if ($user === null) {
            return "Sorry, I can't look that up yet. Please ask your admin to finish setting me up.";
        }

        if ($communityId === '') {
            return "You're not linked to a community yet. Please ask your admin for a code, then send /join YOURCODE.";
        }

        if (! $user->belongsToCommunity($communityId)) {
            return "It looks like you're not a member of that community in Zak yet. An admin can add you.";
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
        if ($question === '') {
            $question = $message->text;
        }

        $allowedLink = ['none', 'recordings', 'meetings', 'assets'];
        if (! in_array($linkMode, $allowedLink, true)) {
            $linkMode = 'none';
        }

        $priorTurns = $this->conversation->turns($this->channelName(), $message->externalUserId);
        $query = $this->conversation->buildKnowledgeQuery($question, $priorTurns);

        $result = $this->aiClient->askGroundedQuestion(
            query: $query,
            tenantId: $community->tenant_id,
            communityIds: [$communityId],
            targetLanguage: $targetLanguage,
            linkMode: $linkMode,
        );

        $result = $this->citationRevalidator->revalidate($user, $result);

        $chatType = (string) ($message->raw['chat_type'] ?? 'private');
        $reply = $this->formatAskReply($result, $chatType, $question, $linkMode);

        $softHandoff = str_starts_with($reply, "I don't have a solid answer for that yet.");
        $shouldEscalate = ($result->answer === '' || $softHandoff)
            && ! $this->isTransientAiFailure($result)
            && $this->conversation->shouldEscalateKnowledgeGap($question, $community->description);

        if ($softHandoff && ! $shouldEscalate) {
            $reply = $this->modelAssistedReply(
                $question,
                'out_of_scope',
                $community,
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
                'community_id' => $communityId,
                'community_name' => $community->name,
                'reason' => (string) ($result->escalationReason ?? 'insufficient_evidence'),
                'channel' => $this->channelName(),
            ]);
        }

        $this->conversation->remember($this->channelName(), $message->externalUserId, 'user', $message->text);
        $this->conversation->remember($this->channelName(), $message->externalUserId, 'assistant', $reply);

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
    ): string {
        $isPrivate = in_array($chatType, ['private', ''], true);

        if ($result->answer === '') {
            if ($this->isTransientAiFailure($result)) {
                return "Sorry, I can't get to that right now. Mind trying again in a bit?";
            }

            if ($isPrivate) {
                return "I don't have a solid answer for that yet.\n\n"
                    ."I've passed it along, and I'll come back once I have one.";
            }

            return "I don't have a solid answer for that yet.\n\n"
                ."I've passed it along, and we'll come back once we have one.";
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
        );
        $answer = $this->ensureListHasIntro(
            $answer,
            $originalQuestion !== '' ? $originalQuestion : $result->query,
            $linkMode,
        );

        // Hollow "Recording Links" style replies with nothing openable → same soft handoff.
        if ($answer === '') {
            if ($isPrivate) {
                return "I don't have a solid answer for that yet.\n\n"
                    ."I've passed it along, and I'll come back once I have one.";
            }

            return "I don't have a solid answer for that yet.\n\n"
                ."I've passed it along, and we'll come back once we have one.";
        }

        if ($result->citations !== []) {
            $source = $this->friendlySourceName($result->citations[0]->sourceName);
            $answer .= "\n\n(From {$source}.)";
        }

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

        $candidateUrls = $this->uniqueOpenableUrls(
            array_merge($urlsInAnswer, $evidenceUrls),
            mode: $mode === 'none' ? 'default' : $mode,
        );

        // Rebuild complete lists only for meeting / recording / asset link asks.
        // Never replace solid prose (e.g. "Who's Diane?") with unrelated URLs.
        if ($wantsAnyLinkList && $candidateUrls !== []) {
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
        if (($wantsRecordings || $wantsMeetingLinks) && $candidateUrls === []) {
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
            if ($url === '') {
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
            return "It looks like you're not a member of that community in Zak yet. An admin can add you.";
        }

        $community = Community::query()->findOrFail($communityId);
        $body = trim($message->text);
        foreach (['/SHARE', 'SHARE', '/EXPORT', 'EXPORT'] as $prefix) {
            if (str_starts_with(strtoupper($body), $prefix)) {
                $body = trim(substr($body, strlen($prefix)));
                break;
            }
        }

        if ($body === '') {
            return "Please include the note, like:\n"
                ."/share Water off tomorrow morning\n\n"
                ."An admin will review it before it joins the knowledge base.";
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

        Log::info('telegram_spike.share_draft', ['knowledge_id' => $source->id]);

        return "Thank you. I've saved that as a draft for an admin to review. "
            ."It won't appear in answers until someone publishes it.";
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
