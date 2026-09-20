<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Internal;

use App\DTOs\Channels\InboundMessage;
use App\Http\Controllers\Controller;
use App\Services\Channels\WhatsAppWebSpikeAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WhatsAppWebSpikeController extends Controller
{
    public function __construct(
        private readonly WhatsAppWebSpikeAdapter $adapter,
    ) {}

    public function inbound(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'string', 'max:64'],
            'text' => ['required', 'string', 'max:4000'],
            'message_id' => ['nullable', 'string', 'max:128'],
        ]);

        $reply = $this->adapter->handleInbound(
            InboundMessage::fromSpikePayload($validated)
        );

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
