<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DTOs\Channels\InboundMessage;
use App\Services\Channels\ChannelConversationService;
use App\Services\Channels\SpikeOutboundSender;
use App\Services\Channels\WhatsAppWebSpikeAdapter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProcessWhatsAppWebSpikeInbound implements ShouldQueue
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
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly array $payload,
    ) {
        $this->onQueue((string) config('zak.queues.channels', 'channels'));
    }

    public function handle(WhatsAppWebSpikeAdapter $adapter): void
    {
        $messageId = trim((string) ($this->payload['message_id'] ?? ''));
        if ($messageId !== '' && Cache::get('wa_web_spike_msg:'.$messageId) === 'done') {
            return;
        }

        // Rethrow on failure so Redis retries; soft deferral goes out only in failed().
        $reply = $adapter->handleInbound(
            InboundMessage::fromSpikePayload($this->payload)
        );

        if ($reply === null || trim($reply) === '') {
            if ($messageId !== '') {
                Cache::put('wa_web_spike_msg:'.$messageId, 'done', now()->addDay());
            }

            return;
        }

        $this->sendReply($reply, app(SpikeOutboundSender::class));
        if ($messageId !== '') {
            Cache::put('wa_web_spike_msg:'.$messageId, 'done', now()->addDay());
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::error('whatsapp_web_spike.job_failed_final', [
            'exception' => $e?->getMessage(),
            'from' => $this->payload['from'] ?? null,
            'message_id' => $this->payload['message_id'] ?? null,
        ]);

        $messageId = trim((string) ($this->payload['message_id'] ?? ''));
        if ($messageId !== '' && Cache::get('wa_web_spike_msg:'.$messageId) === 'done') {
            return;
        }

        $copy = app(ChannelConversationService::class)->transientDeferralReply();
        $this->sendReply($copy, app(SpikeOutboundSender::class));
        if ($messageId !== '') {
            Cache::put('wa_web_spike_msg:'.$messageId, 'done', now()->addDay());
        }
    }

    private function sendReply(string $reply, SpikeOutboundSender $outbound): void
    {
        $messageId = trim((string) ($this->payload['message_id'] ?? ''));
        $isGroup = ! empty($this->payload['is_group'])
            || strtolower((string) ($this->payload['chat_type'] ?? '')) === 'group';
        $chatId = trim((string) ($this->payload['chat_id'] ?? ''));
        $from = trim((string) ($this->payload['from'] ?? ''));
        $to = $isGroup && $chatId !== '' ? $chatId : ($chatId !== '' ? $chatId : $from);
        $mention = $isGroup ? $from : null;

        $sent = $outbound->sendWhatsAppWeb(
            to: $to,
            text: $reply,
            quotedMessageId: $messageId !== '' ? $messageId : null,
            mention: $mention,
            context: [
                'channel' => 'whatsapp_web_spike',
                'message_id' => $messageId !== '' ? $messageId : null,
            ],
        );

        if (! $sent) {
            Log::warning('whatsapp_web_spike.job_reply_not_sent', [
                'to' => $to,
                'message_id' => $messageId !== '' ? $messageId : null,
            ]);
        }
    }
}
