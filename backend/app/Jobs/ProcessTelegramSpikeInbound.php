<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DTOs\Channels\InboundMessage;
use App\Services\Channels\ChannelConversationService;
use App\Services\Channels\SpikeOutboundSender;
use App\Services\Channels\TelegramSpikeAdapter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProcessTelegramSpikeInbound implements ShouldQueue
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

    public function handle(TelegramSpikeAdapter $adapter): void
    {
        $messageId = trim((string) ($this->payload['message_id'] ?? ''));
        $chatId = trim((string) ($this->payload['chat_id'] ?? $this->payload['from'] ?? ''));
        $lockKey = ($messageId !== '' && $chatId !== '')
            ? 'tg_spike_msg:'.$chatId.':'.$messageId
            : null;
        if ($lockKey !== null && Cache::get($lockKey) === 'done') {
            return;
        }

        $outbound = app(SpikeOutboundSender::class);
        if ($chatId !== '') {
            $outbound->sendTelegramTyping($chatId);
        }

        // Rethrow on failure so Redis retries; soft deferral goes out only in failed().
        $reply = $adapter->handleInbound(
            InboundMessage::fromSpikePayload($this->payload, 'telegram_spike')
        );

        $reply = $this->utf8Safe($reply);
        if ($reply === '') {
            if ($lockKey !== null) {
                Cache::put($lockKey, 'done', now()->addDay());
            }

            return;
        }

        if ($chatId === '') {
            Log::warning('telegram_spike.job_missing_chat_id', [
                'message_id' => $messageId !== '' ? $messageId : null,
            ]);

            return;
        }

        $this->sendReply($reply, $chatId, $messageId, $outbound);
        Log::info('telegram_spike.job_sent', [
            'chat_id' => $chatId,
            'message_id' => $messageId !== '' ? $messageId : null,
            'chars' => mb_strlen($reply),
        ]);
        if ($lockKey !== null) {
            Cache::put($lockKey, 'done', now()->addDay());
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::error('telegram_spike.job_failed_final', [
            'exception' => $e?->getMessage(),
            'from' => $this->payload['from'] ?? null,
            'message_id' => $this->payload['message_id'] ?? null,
        ]);

        $chatId = trim((string) ($this->payload['chat_id'] ?? $this->payload['from'] ?? ''));
        $messageId = trim((string) ($this->payload['message_id'] ?? ''));
        $lockKey = ($messageId !== '' && $chatId !== '')
            ? 'tg_spike_msg:'.$chatId.':'.$messageId
            : null;
        if ($chatId === '' || ($lockKey !== null && Cache::get($lockKey) === 'done')) {
            return;
        }

        $copy = app(ChannelConversationService::class)->transientDeferralReply();
        $this->sendReply($copy, $chatId, $messageId, app(SpikeOutboundSender::class));
        if ($lockKey !== null) {
            Cache::put($lockKey, 'done', now()->addDay());
        }
    }

    private function sendReply(
        string $reply,
        string $chatId,
        string $messageId,
        SpikeOutboundSender $outbound,
    ): void {
        $sent = $outbound->sendTelegram(
            chatId: $chatId,
            text: $reply,
            replyToMessageId: $messageId !== '' ? $messageId : null,
            context: [
                'channel' => 'telegram_spike',
                'message_id' => $messageId !== '' ? $messageId : null,
            ],
        );

        if (! $sent) {
            Log::warning('telegram_spike.job_reply_not_sent', [
                'chat_id' => $chatId,
                'message_id' => $messageId !== '' ? $messageId : null,
            ]);
        }
    }

    private function utf8Safe(?string $text): string
    {
        if ($text === null || $text === '') {
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
}
