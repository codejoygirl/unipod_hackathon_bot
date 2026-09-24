<?php

declare(strict_types=1);

namespace App\Services\Channels\Zavu;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ensures voice/image normalizers receive base64 when Zavu sends only a URL.
 */
final class ZavuInboundMediaHydrator
{
    public function __construct(
        private readonly ZavuClient $zavu,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function hydrate(array $raw): array
    {
        $media = is_array($raw['media'] ?? null) ? $raw['media'] : null;
        if ($media === null) {
            return $raw;
        }

        $b64 = trim((string) ($media['data_base64'] ?? $media['base64'] ?? ''));
        if ($b64 !== '') {
            return $raw;
        }

        $url = trim((string) ($media['url'] ?? ''));
        $messageId = trim((string) ($raw['zavu_message_id'] ?? ''));
        $hasMediaId = trim((string) ($media['media_id'] ?? '')) !== '';

        if ($url === '' && $messageId !== '' && $hasMediaId) {
            try {
                $url = $this->zavu->resolveMessageMediaUrl($messageId);
                $media['url'] = $url;
            } catch (Throwable $e) {
                Log::warning('whatsapp_zavu.media_url_resolve_failed', [
                    'message_id' => $messageId,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        if ($url === '') {
            return $raw;
        }

        try {
            $media['data_base64'] = $this->zavu->fetchMediaBase64($url);
            $raw['media'] = $media;
        } catch (Throwable $e) {
            Log::warning('whatsapp_zavu.media_hydrate_failed', [
                'exception' => $e->getMessage(),
                'message_id' => $messageId,
            ]);
        }

        return $raw;
    }
}
