<?php

declare(strict_types=1);

namespace App\Services\Channels;

/**
 * Shared WhatsApp outbound shaping (Zavu Cloud + Web spike).
 */
final class WhatsAppOutboundFormatter
{
    public function __construct(
        private readonly ChannelConversationService $conversation,
    ) {}

    public function format(string $text): string
    {
        $text = $this->toWhatsAppFormatting($text);

        return $this->conversation->formatCommandsInText($text, 'whatsapp');
    }

    /**
     * Convert Markdown emphasis to WhatsApp formatting (*bold*, _italic_).
     */
    private function toWhatsAppFormatting(string $text): string
    {
        $text = str_replace(["\u{2014}", "\u{2013}"], ['-', '-'], $text);
        $text = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/u', "$1\n$2", $text) ?? $text;
        $text = preg_replace('/\*\*(.+?)\*\*/us', '*$1*', $text) ?? $text;
        $text = preg_replace('/__(.+?)__/us', '*$1*', $text) ?? $text;

        return trim($text);
    }
}
