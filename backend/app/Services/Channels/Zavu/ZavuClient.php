<?php

declare(strict_types=1);

namespace App\Services\Channels\Zavu;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class ZavuClient
{
    /**
     * Send a WhatsApp text message via Zavu.
     *
     * @return array<string, mixed>
     */
    public function sendWhatsAppText(string $to, string $text): array
    {
        $apiKey = (string) config('whatsapp_zavu.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('WHATSAPP_ZAVU_API_KEY is not configured.');
        }

        $base = (string) config('whatsapp_zavu.api_base');
        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout(30)
            ->post($base.'/messages', [
                'to' => $to,
                'channel' => 'whatsapp',
                'text' => $text,
            ]);

        if (! $response->successful()) {
            Log::warning('whatsapp_zavu.send_failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'to' => $to,
            ]);

            throw new RuntimeException('Zavu send failed with HTTP '.$response->status());
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];

        return $json;
    }

    /**
     * Mark inbound message read and show WhatsApp typing (auto-clears on reply or ~25s).
     */
    public function showTypingIndicator(string $inboundMessageId): void
    {
        $apiKey = (string) config('whatsapp_zavu.api_key');
        if ($apiKey === '' || trim($inboundMessageId) === '') {
            return;
        }

        $base = (string) config('whatsapp_zavu.api_base');
        $encodedId = rawurlencode(trim($inboundMessageId));
        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout(10)
            ->post("{$base}/messages/{$encodedId}/typing");

        if (! $response->successful()) {
            Log::debug('whatsapp_zavu.typing_failed', [
                'status' => $response->status(),
                'message_id' => $inboundMessageId,
            ]);
        }
    }

    /**
     * Fetch remote media (when webhook sends a URL instead of base64).
     */
    public function fetchMediaBase64(string $url): string
    {
        $apiKey = (string) config('whatsapp_zavu.api_key');
        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout(45)
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException('Zavu media fetch failed with HTTP '.$response->status());
        }

        return base64_encode($response->body());
    }

    /**
     * Inbound webhooks often ship mediaId before mediaUrl; re-read the message until URL is ready.
     */
    public function resolveMessageMediaUrl(string $messageId, int $attempts = 4): string
    {
        $apiKey = (string) config('whatsapp_zavu.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('WHATSAPP_ZAVU_API_KEY is not configured.');
        }

        $base = (string) config('whatsapp_zavu.api_base');
        $encodedId = rawurlencode($messageId);
        $lastStatus = 0;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(25)
                ->get("{$base}/messages/{$encodedId}");

            $lastStatus = $response->status();
            if (! $response->successful()) {
                if ($attempt < $attempts - 1) {
                    usleep(400_000);

                    continue;
                }

                throw new RuntimeException('Zavu message read failed with HTTP '.$lastStatus);
            }

            /** @var array<string, mixed> $json */
            $json = $response->json() ?? [];
            $content = is_array($json['content'] ?? null) ? $json['content'] : [];
            if ($content === [] && is_array($json['data'] ?? null)) {
                $nested = $json['data'];
                if (is_array($nested['content'] ?? null)) {
                    $content = $nested['content'];
                }
            }

            $url = trim((string) ($content['mediaUrl'] ?? $content['media_url'] ?? ''));
            if ($url !== '') {
                return $url;
            }

            if ($attempt < $attempts - 1) {
                usleep(400_000);
            }
        }

        throw new RuntimeException('Zavu message has no mediaUrl yet (messageId='.$messageId.')');
    }
}
