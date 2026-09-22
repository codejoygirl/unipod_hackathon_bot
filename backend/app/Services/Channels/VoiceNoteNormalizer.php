<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\DTOs\Channels\InboundMessage;
use App\Services\AI\AiServiceClient;
use Illuminate\Support\Facades\Log;

/**
 * Turn channel voice notes into plain text before routing.
 * Existing adapters stay text-only; this is the single ingress boundary.
 */
final class VoiceNoteNormalizer
{
    public function __construct(
        private readonly AiServiceClient $aiClient,
        private readonly ChannelConversationService $conversation,
    ) {}

    /**
     * If the inbound payload carries voice media, transcribe and return a new
     * InboundMessage with text + target_language. Otherwise return unchanged.
     *
     * @return array{message: InboundMessage, error: string|null}
     */
    public function normalize(InboundMessage $message): array
    {
        $raw = is_array($message->raw) ? $message->raw : [];
        $media = is_array($raw['media'] ?? null) ? $raw['media'] : null;
        if ($media === null) {
            return ['message' => $message, 'error' => null];
        }

        $kind = strtolower(trim((string) ($media['kind'] ?? '')));
        $mime = strtolower(trim((string) ($media['mime_type'] ?? $media['mimetype'] ?? '')));
        $isVoice = in_array($kind, ['voice', 'ptt', 'audio'], true)
            || str_starts_with($mime, 'audio/');

        if (! $isVoice) {
            return ['message' => $message, 'error' => null];
        }

        $b64 = trim((string) ($media['data_base64'] ?? $media['base64'] ?? ''));
        if ($b64 === '') {
            return [
                'message' => $message,
                'error' => $this->conversation->voiceNoteFailedReply(),
            ];
        }

        // Rough size guard (~2MB decoded) before calling AI.
        if (strlen($b64) > 3_500_000) {
            return [
                'message' => $message,
                'error' => $this->conversation->voiceNoteTooLongReply(),
            ];
        }

        $filename = trim((string) ($media['filename'] ?? ''));
        if ($filename === '') {
            $filename = str_contains($mime, 'ogg') || str_contains($mime, 'opus')
                ? 'voice.ogg'
                : 'voice.m4a';
        }

        $result = $this->aiClient->transcribeVoiceNote(
            audioBase64: $b64,
            mimeType: $mime !== '' ? $mime : null,
            filename: $filename,
        );

        $text = trim((string) ($result['text'] ?? ''));
        if ($text === '') {
            Log::info('channel.voice_note.empty_transcript', [
                'channel' => $message->channel,
                'from' => $message->externalUserId,
            ]);

            return [
                'message' => $message,
                'error' => $this->conversation->voiceNoteFailedReply(),
            ];
        }

        // Caption + transcript when both present. Bare @zak pings on a quoted
        // voice note must not become the "ask" — use the transcript only.
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
        if ($caption !== '' && ! str_contains(mb_strtolower($text), mb_strtolower($caption))) {
            $text = $caption."\n\n".$text;
        }

        $nextRaw = $raw;
        $nextRaw['input_modality'] = 'voice';
        $lang = $result['language'] ?? null;
        if (is_string($lang) && $lang !== '') {
            // Keep Whisper's guess for logs/debug only. Do NOT force reply language from
            // it: short English clips are often mis-tagged (ar/yo/…) and then the whole
            // answer flips language. Reply language follows the transcript text instead.
            $nextRaw['whisper_language'] = strtolower(trim($lang));
        }
        // Drop bulky audio from downstream logs / memory.
        unset($nextRaw['media']);

        Log::info('channel.voice_note.transcribed', [
            'channel' => $message->channel,
            'from' => $message->externalUserId,
            'chars' => mb_strlen($text),
            'whisper_language' => is_string($lang) ? strtolower(trim($lang)) : null,
            'transcript_preview' => mb_substr($text, 0, 240),
            'had_caption' => trim($message->text) !== '',
            'quoted_voice' => filter_var($raw['quoted_voice'] ?? false, FILTER_VALIDATE_BOOLEAN),
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
