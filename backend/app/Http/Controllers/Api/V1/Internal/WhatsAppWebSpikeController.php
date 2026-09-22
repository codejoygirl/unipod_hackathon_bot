<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Internal;

use App\DTOs\Channels\InboundMessage;
use App\Http\Controllers\Controller;
use App\Services\Channels\WhatsAppWebSpikeAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class WhatsAppWebSpikeController extends Controller
{
    public function __construct(
        private readonly WhatsAppWebSpikeAdapter $adapter,
    ) {}

    public function inbound(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'string', 'max:96'],
            'text' => ['required', 'string', 'max:4000'],
            'message_id' => ['nullable', 'string', 'max:128'],
            'chat_type' => ['nullable', 'string', 'max:32'],
            'is_group' => ['nullable', 'boolean'],
            'from_name' => ['nullable', 'string', 'max:128'],
            'from_phone' => ['nullable', 'string', 'max:32'],
            'bot_number' => ['nullable', 'string', 'max:32'],
            'bot_lid' => ['nullable', 'string', 'max:64'],
            'bot_mentioned' => ['nullable', 'boolean'],
            'reply_to_bot' => ['nullable', 'boolean'],
            'chat_id' => ['nullable', 'string', 'max:128'],
            'quoted_text' => ['nullable', 'string', 'max:2000'],
            'quoted_from' => ['nullable', 'string', 'max:64'],
            'quoted_message_id' => ['nullable', 'string', 'max:128'],
            'mentions' => ['nullable', 'array', 'max:20'],
            'mentions.*.id' => ['nullable', 'string', 'max:64'],
            'mentions.*.name' => ['nullable', 'string', 'max:128'],
            'mentions.*.phone' => ['nullable', 'string', 'max:32'],
        ]);

        if (! isset($validated['chat_type']) && ! empty($validated['is_group'])) {
            $validated['chat_type'] = 'group';
        }

        try {
            $reply = $this->adapter->handleInbound(
                InboundMessage::fromSpikePayload($validated)
            );
        } catch (\Throwable $e) {
            Log::error('whatsapp_web_spike.controller_inbound_failed', [
                'error' => $e->getMessage(),
                'from' => $validated['from'] ?? null,
            ]);
            $reply = "I hit a snag answering that just now. "
                .'Mind sending it again in a moment?';
        }

        return response()->json([
            'data' => [
                'reply' => $reply,
                'channel' => $this->adapter->channelName(),
            ],
        ]);
    }

    public function mintJoin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'community_id' => ['required', 'ulid', 'exists:communities,id'],
        ]);

        $join = WhatsAppWebSpikeAdapter::mintJoinToken($validated['community_id']);

        return response()->json([
            'data' => [
                'join_message' => $join,
                'hint' => 'Send this exact text to the linked WhatsApp Web spike number.',
            ],
        ]);
    }
}
