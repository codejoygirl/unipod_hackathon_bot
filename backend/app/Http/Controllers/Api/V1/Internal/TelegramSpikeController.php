<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Internal;

use App\DTOs\Channels\InboundMessage;
use App\Http\Controllers\Controller;
use App\Services\Channels\TelegramSpikeAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TelegramSpikeController extends Controller
{
    public function __construct(
        private readonly TelegramSpikeAdapter $adapter,
    ) {}

    public function inbound(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'string', 'max:64'],
            'text' => ['required', 'string', 'max:4000'],
            'message_id' => ['nullable', 'string', 'max:128'],
            'reply_to_message_id' => ['nullable', 'string', 'max:128'],
            'target_language' => ['nullable', 'string', 'max:10'],
            'chat_type' => ['nullable', 'string', 'max:32'],
            'chat_id' => ['nullable', 'string', 'max:64'],
            'from_name' => ['nullable', 'string', 'max:128'],
            'from_username' => ['nullable', 'string', 'max:64'],
            'bot_mentioned' => ['nullable', 'boolean'],
            'reply_to_bot' => ['nullable', 'boolean'],
            'quoted_text' => ['nullable', 'string', 'max:2000'],
        ]);

        $reply = $this->adapter->handleInbound(
            InboundMessage::fromSpikePayload($validated, 'telegram_spike')
        );

        return response()->json([
            'data' => [
                'reply' => $this->utf8Safe($reply),
                'channel' => $this->adapter->channelName(),
            ],
        ], 200, [], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    }

    public function mintJoin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'community_id' => ['required', 'ulid', 'exists:communities,id'],
        ]);

        $join = TelegramSpikeAdapter::mintJoinToken($validated['community_id']);

        return response()->json([
            'data' => [
                'join_code' => $join,
                'join_command' => '/join '.$join,
                'hint' => 'In Telegram, send: /join '.$join,
            ],
        ]);
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

        // Drop leftover control chars that can still break JSON / Telegram.
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $clean) ?? $clean;

        $encoded = json_encode($clean, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return '';
        }

        $decoded = json_decode($encoded, true);

        return is_string($decoded) ? $decoded : '';
    }
}
