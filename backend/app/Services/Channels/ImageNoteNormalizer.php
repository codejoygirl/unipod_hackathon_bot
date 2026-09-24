<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\DTOs\Channels\InboundMessage;
use App\Services\AI\AiServiceClient;
use Illuminate\Support\Facades\Log;

/**
 * Turn channel / web photos into plain text before routing.
 * Mirrors VoiceNoteNormalizer: adapters stay text-only after this boundary.
 */
final class ImageNoteNormalizer
{
    /** @var list<string> */
    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    public function __construct(
        private readonly AiServiceClient $aiClient,
        private readonly ChannelConversationService $conversation,
    ) {}

    /**
     * @return array{message: InboundMessage, error: string|null}
     */
    public function normalize(InboundMessage $message): array
    {
        $raw = is_array($message->raw) ? $message->raw : [];
        $batch = is_array($raw['images'] ?? null) ? $raw['images'] : [];
        if ($batch !== []) {
            return $this->normalizeMany($message, $batch);
        }

        $media = is_array($raw['media'] ?? null) ? $raw['media'] : null;
        if ($media === null) {
            return ['message' => $message, 'error' => null];
        }

        $kind = strtolower(trim((string) ($media['kind'] ?? '')));
        $mime = strtolower(trim((string) ($media['mime_type'] ?? $media['mimetype'] ?? '')));
        $mime = explode(';', $mime, 2)[0];
        $isImage = in_array($kind, ['image', 'photo', 'sticker', 'picture'], true)
            || str_starts_with($mime, 'image/');

        if (! $isImage) {
            return ['message' => $message, 'error' => null];
        }

        $b64 = trim((string) ($media['data_base64'] ?? $media['base64'] ?? ''));
        if (str_starts_with(strtolower($b64), 'data:') && str_contains($b64, ',')) {
            $b64 = trim(substr($b64, (int) strpos($b64, ',') + 1));
        }
        if ($b64 === '') {
            return [
                'message' => $message,
                'error' => $this->conversation->imageNoteFailedReply(),
            ];
        }

        if (strlen($b64) > 3_500_000) {
            return [
                'message' => $message,
                'error' => $this->conversation->imageNoteTooLargeReply(),
            ];
        }

        if ($mime !== '' && ! in_array($mime, self::ALLOWED_MIMES, true)) {
            return [
                'message' => $message,
                'error' => $this->conversation->imageNoteFailedReply(),
            ];
        }

        $filename = trim((string) ($media['filename'] ?? ''));
        if ($filename === '') {
            $filename = 'photo.jpg';
        }

        $caption = trim($message->text);
        $aliases = [];
        foreach (['bot_number', 'bot_lid'] as $key) {
            $v = trim((string) ($raw[$key] ?? ''));
            if ($v !== '') {
                $aliases[] = $v;
            }
        }
        if ($caption !== '' && $this->conversation->isBareBotPing($caption, $aliases)) {
            $caption = '';
        }

        $result = $this->aiClient->understandImage(
            imageBase64: $b64,
            mimeType: $mime !== '' ? $mime : 'image/jpeg',
            filename: $filename,
            caption: $caption !== '' ? $caption : null,
        );

        $text = trim((string) ($result['text'] ?? ''));
        if ($text === '') {
            Log::info('channel.image_note.empty_extract', [
                'channel' => $message->channel,
                'from' => $message->externalUserId,
                'mime' => $mime !== '' ? $mime : 'image/jpeg',
                'b64_chars' => strlen($b64),
                'ai_http_status' => $result['http_status'] ?? null,
                'ai_unreachable' => (bool) ($result['unreachable'] ?? false),
                'had_caption' => $caption !== '',
            ]);

            if ($caption !== '') {
                Log::info('channel.image_note.caption_fallback', [
                    'channel' => $message->channel,
                    'from' => $message->externalUserId,
                ]);
                $text = $caption;
            } else {
                return [
                    'message' => $message,
                    'error' => $this->conversation->imageNoteFailedReply(),
                ];
            }
        }

        if ($caption !== '' && ! str_contains(mb_strtolower($text), mb_strtolower($caption))) {
            $text = $caption."\n\n".$text;
        }

        $nextRaw = $raw;
        $nextRaw['input_modality'] = 'image';
        unset($nextRaw['media']);

        Log::info('channel.image_note.understood', [
            'channel' => $message->channel,
            'from' => $message->externalUserId,
            'chars' => mb_strlen($text),
            'extract_preview' => mb_substr($text, 0, 240),
            'had_caption' => trim($message->text) !== '',
        ]);

        return [
            'message' => new InboundMessage(
                channel: $message->channel,
                externalUserId: $message->externalUserId,
                text: $text,
                messageId: $message->messageId,
                raw: $nextRaw,
            ),
            'error' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $images
     * @return array{message: InboundMessage, error: string|null}
     */
    private function normalizeMany(InboundMessage $message, array $images): array
    {
        $caption = trim($message->text);
        $payload = [];
        foreach ($images as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $b64 = trim((string) ($item['data_base64'] ?? $item['base64'] ?? ''));
            if ($b64 === '') {
                continue;
            }
            if (strlen($b64) > 3_500_000) {
                return [
                    'message' => $message,
                    'error' => $this->conversation->imageNoteTooLargeReply(),
                ];
            }
            $mime = strtolower(trim((string) ($item['mime_type'] ?? $item['mimetype'] ?? 'image/jpeg')));
            $mime = explode(';', $mime, 2)[0];
            if ($mime !== '' && ! in_array($mime, self::ALLOWED_MIMES, true)) {
                return [
                    'message' => $message,
                    'error' => $this->conversation->imageNoteFailedReply(),
                ];
            }
            $filename = trim((string) ($item['filename'] ?? ''));
            if ($filename === '') {
                $filename = 'photo-'.($index + 1).'.jpg';
            }
            $payload[] = [
                'image_base64' => $b64,
                'mime_type' => $mime !== '' ? $mime : 'image/jpeg',
                'filename' => $filename,
            ];
        }

        if ($payload === []) {
            return [
                'message' => $message,
                'error' => $this->conversation->imageNoteFailedReply(),
            ];
        }

        $result = $this->aiClient->understandImages($payload, $caption !== '' ? $caption : null);
        $text = trim((string) ($result['text'] ?? ''));
        if ($text === '') {
            if ($caption !== '') {
                $text = $caption;
            } else {
                return [
                    'message' => $message,
                    'error' => $this->conversation->imageNoteFailedReply(),
                ];
            }
        } elseif ($caption !== '' && ! str_contains(mb_strtolower($text), mb_strtolower($caption))) {
            $text = $caption."\n\n".$text;
        }

        $nextRaw = is_array($message->raw) ? $message->raw : [];
        $nextRaw['input_modality'] = 'image';
        unset($nextRaw['media'], $nextRaw['images']);

        Log::info('channel.image_note.understood_batch', [
            'channel' => $message->channel,
            'from' => $message->externalUserId,
            'count' => count($payload),
            'chars' => mb_strlen($text),
        ]);

        return [
            'message' => new InboundMessage(
                channel: $message->channel,
                externalUserId: $message->externalUserId,
                text: $text,
                messageId: $message->messageId,
                raw: $nextRaw,
            ),
            'error' => null,
        ];
    }
}
