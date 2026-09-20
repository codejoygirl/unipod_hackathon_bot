<?php

declare(strict_types=1);

namespace App\DTOs\Channels;

final class InboundMessage
{
    public function __construct(
        public readonly string $channel,
        public readonly string $externalUserId,
        public readonly string $text,
        public readonly ?string $messageId = null,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromSpikePayload(array $payload, string $channel = 'whatsapp_web_spike'): self
    {
        return new self(
            channel: $channel,
            externalUserId: (string) ($payload['from'] ?? ''),
            text: trim((string) ($payload['text'] ?? '')),
            messageId: isset($payload['message_id']) ? (string) $payload['message_id'] : null,
            raw: $payload,
        );
    }
}
