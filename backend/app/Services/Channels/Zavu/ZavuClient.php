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
}
