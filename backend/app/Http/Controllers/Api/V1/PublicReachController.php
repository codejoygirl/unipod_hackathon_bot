<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Channels\WhatsAppPresence;
use Illuminate\Http\JsonResponse;

final class PublicReachController extends Controller
{
    public function __invoke(WhatsAppPresence $whatsapp): JsonResponse
    {
        $telegramUrl = trim((string) config('zak_presence.telegram_url', ''));
        if ($telegramUrl === '') {
            $handle = ltrim(trim((string) config('zak_presence.telegram_handle', '')), '@');
            if ($handle !== '') {
                $telegramUrl = 'https://t.me/'.$handle;
            }
        }

        $webUrl = '';
        if (filter_var(config('zak_presence.show_web_chat', true), FILTER_VALIDATE_BOOLEAN)) {
            $webUrl = app(\App\Services\WebChat\WebChatUrlBuilder::class)->inviteUrl(null);
        }

        return response()->json([
            'data' => [
                'whatsapp' => array_map(
                    static fn (array $row): array => [
                        'transport' => $row['transport_id'],
                        'channel_key' => $row['channel_key'],
                        'label' => $row['label'],
                        'url' => $row['url'],
                    ],
                    $whatsapp->memberFacingReachEntries(true),
                ),
                'telegram' => [
                    'label' => (string) config('zak_presence.telegram_label', 'Telegram'),
                    'url' => $telegramUrl !== '' ? $telegramUrl : null,
                ],
                'web' => [
                    'label' => (string) config('zak_presence.web_chat_label', 'Web Chat'),
                    'url' => $webUrl !== '' ? $webUrl : null,
                ],
                'primary_whatsapp_transport' => (string) config('zak_whatsapp.primary_transport', 'zavu'),
            ],
        ]);
    }
}
