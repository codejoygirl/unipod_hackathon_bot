<?php

declare(strict_types=1);

namespace App\Services\Channels;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shared outbound for spike channel jobs (member replies after queue processing).
 * Reuses the same transports as SpikeEscalationNotifier (Telegram Bot API / WA /send).
 */
final class SpikeOutboundSender
{
    /**
     * Show "typing…" in Telegram (expires ~5s). Call at job start and before send.
     */
    public function sendTelegramTyping(string $chatId): void
    {
        $token = (string) config('telegram_spike.bot_token', '');
        if ($token === '' || $chatId === '') {
            return;
        }

        try {
            Http::timeout(5)
                ->connectTimeout(3)
                ->withOptions(['force_ip_resolve' => 'v4'])
                ->asJson()
                ->post(
                    "https://api.telegram.org/bot{$token}/sendChatAction",
                    [
                        'chat_id' => $chatId,
                        'action' => 'typing',
                    ],
                );
        } catch (\Throwable $e) {
            Log::debug('spike.outbound.telegram_typing_failed', [
                'error' => $e->getMessage(),
                'chat_id' => $chatId,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function sendTelegram(
        string $chatId,
        string $text,
        ?string $replyToMessageId = null,
        array $context = [],
    ): bool {
        $token = (string) config('telegram_spike.bot_token', '');
        if ($token === '' || $chatId === '' || trim($text) === '') {
            return false;
        }

        $this->sendTelegramTyping($chatId);

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
        ];
        if ($replyToMessageId !== null && $replyToMessageId !== '') {
            $payload['reply_to_message_id'] = (int) $replyToMessageId;
            $payload['allow_sending_without_reply'] = true;
        }

        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->withOptions(['force_ip_resolve' => 'v4'])
                ->asJson()
                ->post(
                    "https://api.telegram.org/bot{$token}/sendMessage",
                    $payload,
                );
        } catch (\Throwable $e) {
            Log::error('spike.outbound.telegram_exception', [
                'error' => $e->getMessage(),
                'chat_id' => $chatId,
                ...$context,
            ]);

            return false;
        }

        if ($response->failed()) {
            // Retry once without reply thread if Telegram rejects reply_to_message_id.
            if (($payload['reply_to_message_id'] ?? null) !== null) {
                unset($payload['reply_to_message_id'], $payload['allow_sending_without_reply']);
                try {
                    $response = Http::timeout(15)
                        ->connectTimeout(5)
                        ->withOptions(['force_ip_resolve' => 'v4'])
                        ->asJson()
                        ->post(
                            "https://api.telegram.org/bot{$token}/sendMessage",
                            $payload,
                        );
                } catch (\Throwable $e) {
                    Log::error('spike.outbound.telegram_exception', [
                        'error' => $e->getMessage(),
                        'chat_id' => $chatId,
                        'retry' => 'no_reply_to',
                        ...$context,
                    ]);

                    return false;
                }
            }
        }

        if ($response->failed()) {
            Log::error('spike.outbound.telegram_failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'chat_id' => $chatId,
                ...$context,
            ]);

            return false;
        }

        Log::info('spike.outbound.telegram_ok', [
            'chat_id' => $chatId,
            'chars' => mb_strlen($text),
            ...$context,
        ]);

        return true;
    }

    /**
     * @param  list<string>  $mentions
     * @param  array<string, mixed>  $context
     */
    public function sendWhatsAppWeb(
        string $to,
        string $text,
        ?string $quotedMessageId = null,
        ?string $mention = null,
        array $mentions = [],
        array $context = [],
    ): bool {
        $base = rtrim((string) config('whatsapp_web_spike.outbound_url', ''), '/');
        $secret = (string) config('whatsapp_web_spike.shared_secret', '');
        if ($base === '' || $secret === '' || $to === '' || trim($text) === '') {
            return false;
        }

        $payload = [
            'secret' => $secret,
            'to' => $to,
            'text' => $text,
        ];
        if ($quotedMessageId !== null && $quotedMessageId !== '') {
            $payload['quoted_message_id'] = $quotedMessageId;
        }
        if ($mention !== null && $mention !== '') {
            $payload['mention'] = $mention;
        }
        if ($mentions !== []) {
            $payload['mentions'] = array_values(array_filter(array_map('strval', $mentions)));
        }

        try {
            $response = Http::timeout(30)->asJson()->post($base.'/send', $payload);
        } catch (\Throwable $e) {
            Log::error('spike.outbound.whatsapp_exception', [
                'error' => $e->getMessage(),
                'to' => $to,
                ...$context,
            ]);

            return false;
        }

        if ($response->failed()) {
            Log::error('spike.outbound.whatsapp_failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'to' => $to,
                ...$context,
            ]);

            return false;
        }

        return true;
    }
}
