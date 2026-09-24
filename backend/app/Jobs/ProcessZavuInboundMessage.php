<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DTOs\Channels\InboundMessage;
use App\Services\Channels\WhatsAppZavuAdapter;
use App\Services\Channels\Zavu\ZavuClient;
use App\Services\Channels\Zavu\ZavuInboundMediaHydrator;
use App\Services\Channels\Zavu\ZavuInboundPayloadMapper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProcessZavuInboundMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30, 90];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function __construct(
        public readonly array $event,
    ) {
        $this->onQueue((string) config('zak.queues.channels', 'channels'));
    }

    public function handle(
        WhatsAppZavuAdapter $adapter,
        ZavuClient $zavu,
        ZavuInboundPayloadMapper $mapper,
        ZavuInboundMediaHydrator $mediaHydrator,
    ): void {
        $eventId = (string) ($this->event['id'] ?? '');
        if ($eventId !== '') {
            $lockKey = 'wa_zavu_event:'.$eventId;
            if (! Cache::add($lockKey, 1, now()->addDay())) {
                return;
            }
        }

        $type = (string) ($this->event['type'] ?? '');
        if ($type !== 'message.inbound') {
            return;
        }

        $data = $this->event['data'] ?? [];
        if (! is_array($data)) {
            return;
        }

        $channel = strtolower((string) ($data['channel'] ?? 'whatsapp'));
        if ($channel !== '' && $channel !== 'whatsapp') {
            return;
        }

        $from = trim((string) ($data['from'] ?? ''));
        $text = $mapper->inboundText($data);
        if ($from === '' || ($text === '' && ! $mapper->hasMedia($data))) {
            return;
        }

        $raw = $mediaHydrator->hydrate($mapper->mapRaw($data));

        $inboundMessageId = trim((string) ($data['messageId'] ?? $data['id'] ?? ''));
        if ($inboundMessageId !== ''
            && filter_var(config('whatsapp_zavu.typing_indicator', true), FILTER_VALIDATE_BOOLEAN)) {
            try {
                $zavu->showTypingIndicator($inboundMessageId);
            } catch (Throwable $typingError) {
                Log::debug('whatsapp_zavu.typing_skipped', [
                    'message_id' => $inboundMessageId,
                    'exception' => $typingError->getMessage(),
                ]);
            }
        }

        $message = new InboundMessage(
            channel: 'whatsapp_zavu',
            externalUserId: $from,
            text: $text,
            messageId: $inboundMessageId !== '' ? $inboundMessageId : null,
            raw: $raw,
        );

        try {
            $reply = $adapter->handleInbound($message);
        } catch (Throwable $e) {
            Log::error('whatsapp_zavu.handle_failed', [
                'exception' => $e->getMessage(),
                'from' => $from,
            ]);

            return;
        }

        if ($reply === null || trim($reply) === '') {
            return;
        }

        try {
            $zavu->sendWhatsAppText($from, $reply);
        } catch (Throwable $e) {
            Log::error('whatsapp_zavu.reply_send_failed', [
                'exception' => $e->getMessage(),
                'from' => $from,
            ]);
        }
    }
}
