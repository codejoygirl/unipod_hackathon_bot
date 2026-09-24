<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Internal;

use App\DTOs\Channels\InboundMessage;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppWebSpikeInbound;
use App\Services\Channels\ChannelConversationService;
use App\Services\Channels\ChannelListenGate;
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
            'text' => ['nullable', 'string', 'max:4000'],
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
            'media' => ['nullable', 'array'],
            'media.kind' => ['nullable', 'string', 'max:32'],
            'media.mime_type' => ['nullable', 'string', 'max:120'],
            'media.filename' => ['nullable', 'string', 'max:200'],
            'media.data_base64' => ['nullable', 'string', 'max:3500000'],
            'target_language' => ['nullable', 'string', 'max:10'],
        ]);

        $validated['text'] = trim((string) ($validated['text'] ?? ''));
        $hasMedia = is_array($validated['media'] ?? null)
            && trim((string) (($validated['media']['data_base64'] ?? ''))) !== '';
        if ($validated['text'] === '' && ! $hasMedia) {
            return response()->json([
                'data' => [
                    'reply' => null,
                    'accepted' => false,
                    'queued' => false,
                    'channel' => $this->adapter->channelName(),
                ],
            ]);
        }

        if (! isset($validated['chat_type']) && ! empty($validated['is_group'])) {
            $validated['chat_type'] = 'group';
        }

        $listenGate = app(ChannelListenGate::class);
        $processSync = (bool) config('whatsapp_web_spike.process_sync', true)
            || $listenGate->spikeNeedsInlineReply($validated['text'], $validated);

        // Default sync so local spikes keep waiting for data.reply (no behavior break).
        if ($processSync) {
            try {
                $reply = $this->adapter->handleInbound(
                    InboundMessage::fromSpikePayload($validated)
                );
            } catch (\Throwable $e) {
                Log::error('whatsapp_web_spike.controller_inbound_failed', [
                    'error' => $e->getMessage(),
                    'from' => $validated['from'] ?? null,
                ]);
                $reply = app(ChannelConversationService::class)
                    ->transientDeferralReply();
            }

            return response()->json([
                'data' => [
                    'reply' => $reply,
                    'accepted' => true,
                    'queued' => false,
                    'channel' => $this->adapter->channelName(),
                ],
            ]);
        }

        ProcessWhatsAppWebSpikeInbound::dispatch($validated)->afterResponse();

        return response()->json([
            'data' => [
                'reply' => null,
                'accepted' => true,
                'queued' => true,
                'channel' => $this->adapter->channelName(),
            ],
        ], 202);
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
