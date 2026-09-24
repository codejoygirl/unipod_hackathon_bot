<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\DTOs\Channels\InboundMessage;

/**
 * Fold WhatsApp/Telegram swipe-quote context into routing and RAG queries (web spike parity).
 */
final class ChannelSwipeQuoteContext
{
    public function __construct(
        private readonly ChannelCommandAccess $commandAccess,
        private readonly ChannelConversationService $conversation,
    ) {}

    /**
     * Text used for intent routing. Bare @zak on a quote → use the quoted question.
     */
    public function routingText(InboundMessage $message): string
    {
        $text = trim($message->text);
        $quoted = trim((string) ($message->raw['quoted_text'] ?? ''));
        $replyToBot = filter_var($message->raw['reply_to_bot'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($this->isVoiceOrMediaPlaceholder($quoted)) {
            $quoted = '';
        }

        if ($quoted !== '' && $this->isBareBotMention($text)) {
            if ($replyToBot) {
                return $text;
            }

            return $quoted;
        }

        if ($replyToBot) {
            return $text;
        }

        return $this->foldIntoKnowledgeQuery($message, $text);
    }

    /**
     * When the member quoted another message, fold that quote into the knowledge query.
     */
    public function foldIntoKnowledgeQuery(InboundMessage $message, string $query): string
    {
        $query = trim($query);
        if ($this->shouldSkipQuotedFold($message, $query)) {
            return $query;
        }

        $quoted = trim((string) ($message->raw['quoted_text'] ?? ''));
        if ($quoted === '' || $this->isVoiceOrMediaPlaceholder($quoted)) {
            return $query;
        }

        $raw = is_array($message->raw) ? $message->raw : [];
        if ($this->commandAccess->looksLikeEscalationCardReply($raw)) {
            return $query;
        }

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

        if ($query === $quoted || str_starts_with($query, 'Regarding this ')
            || str_starts_with($query, 'The member is following up')) {
            return $query;
        }

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

        $text = trim($message->text);
        if ($this->conversation->isContextualFollowUp($text)
            || $this->conversation->isContextualFollowUp($query)
            || str_starts_with($query, 'The member is following up')) {
            return true;
        }

        return true;
    }

    private function isVoiceOrMediaPlaceholder(string $quoted): bool
    {
        $q = mb_strtolower(trim($quoted));

        return $q === ''
            || $q === '[voice note]'
            || $q === '[voice]'
            || $q === '(voice note)'
            || $q === '[photo]'
            || $q === '[image]'
            || preg_match('/^\[?\s*voice(?:\s+note)?\s*\]?$/u', $q) === 1
            || preg_match('/^\[?\s*(?:photo|image|picture)\s*\]?$/u', $q) === 1;
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
}
