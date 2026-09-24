<?php

declare(strict_types=1);

namespace App\Services\Channels\Zavu;

/**
 * Normalizes Zavu webhook payloads into channel raw fields (media, phone, reply context).
 */
final class ZavuInboundPayloadMapper
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function mapRaw(array $data): array
    {
        $raw = $data;
        $raw['from_phone'] = trim((string) ($data['from'] ?? ''));
        $raw['chat_type'] = 'private';
        $raw['zavu_message_id'] = trim((string) ($data['messageId'] ?? $data['id'] ?? ''));

        $replyTo = is_array($data['replyTo'] ?? null) ? $data['replyTo'] : null;
        if ($replyTo !== null) {
            $raw['reply_to_bot'] = filter_var($replyTo['fromBot'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $raw['quoted_text'] = trim((string) ($replyTo['text'] ?? $replyTo['body'] ?? ''));
            $raw['quoted_message_id'] = trim((string) ($replyTo['messageId'] ?? $replyTo['id'] ?? ''));
            $quotedFrom = trim((string) ($replyTo['from'] ?? $replyTo['fromPhone'] ?? ''));
            if ($quotedFrom !== '') {
                $raw['quoted_from'] = $quotedFrom;
            }
        }

        $content = is_array($data['content'] ?? null) ? $data['content'] : [];
        if ($content !== []) {
            $replySnippet = trim((string) ($content['replyToText'] ?? ''));
            if ($replySnippet !== '' && trim((string) ($raw['quoted_text'] ?? '')) === '') {
                $raw['quoted_text'] = $replySnippet;
            }

            $replyZavuId = trim((string) ($content['replyToMessageId'] ?? ''));
            if ($replyZavuId !== '' && trim((string) ($raw['quoted_message_id'] ?? '')) === '') {
                $raw['quoted_message_id'] = $replyZavuId;
            }

            $replyProviderId = trim((string) ($content['replyToProviderMessageId'] ?? ''));
            if ($replyProviderId !== '') {
                $raw['quoted_provider_message_id'] = $replyProviderId;
            }

            $replyFrom = trim((string) ($content['replyToFrom'] ?? ''));
            if ($replyFrom !== '' && trim((string) ($raw['quoted_from'] ?? '')) === '') {
                $raw['quoted_from'] = $replyFrom;
            }

            if (! array_key_exists('reply_to_bot', $raw)
                && filter_var($content['replyToFromBot'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $raw['reply_to_bot'] = true;
            }
        }

        $media = $this->extractMedia($data);
        if ($media !== null) {
            $raw['media'] = $media;
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function extractMedia(array $data): ?array
    {
        $fromContent = $this->extractMediaFromZavuContent($data);
        if ($fromContent !== null) {
            return $fromContent;
        }

        $candidates = [
            $data['media'] ?? null,
            $data['attachment'] ?? null,
            $data['attachments'][0] ?? null,
        ];

        foreach ($candidates as $item) {
            if (! is_array($item)) {
                continue;
            }

            $mapped = $this->mapMediaItem($item);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        $type = strtolower(trim((string) ($data['type'] ?? $data['messageType'] ?? '')));
        if ($type === 'audio' || $type === 'voice' || $type === 'ptt') {
            return $this->mapMediaItem([
                'kind' => 'voice',
                'mime_type' => (string) ($data['mimeType'] ?? 'audio/ogg'),
                'data_base64' => $data['data_base64'] ?? $data['base64'] ?? null,
                'url' => $data['mediaUrl'] ?? $data['url'] ?? null,
            ]);
        }

        if ($type === 'image' || $type === 'photo') {
            return $this->mapMediaItem([
                'kind' => 'image',
                'mime_type' => (string) ($data['mimeType'] ?? 'image/jpeg'),
                'data_base64' => $data['data_base64'] ?? $data['base64'] ?? null,
                'url' => $data['mediaUrl'] ?? $data['url'] ?? null,
            ]);
        }

        if ($type === 'document' || $type === 'file') {
            return $this->mapMediaItem([
                'kind' => 'document',
                'mime_type' => (string) ($data['mimeType'] ?? 'application/pdf'),
                'filename' => (string) ($data['filename'] ?? $data['fileName'] ?? 'document.pdf'),
                'data_base64' => $data['data_base64'] ?? $data['base64'] ?? null,
                'url' => $data['mediaUrl'] ?? $data['url'] ?? null,
            ]);
        }

        if ($type === 'video') {
            return $this->mapMediaItem([
                'kind' => 'video',
                'mime_type' => (string) ($data['mimeType'] ?? 'video/mp4'),
                'data_base64' => $data['data_base64'] ?? $data['base64'] ?? null,
                'url' => $data['mediaUrl'] ?? $data['url'] ?? null,
            ]);
        }

        return null;
    }

    /**
     * Zavu message.inbound: messageType + content.mediaId / content.mediaUrl.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function extractMediaFromZavuContent(array $data): ?array
    {
        $messageType = strtolower(trim((string) ($data['messageType'] ?? $data['type'] ?? '')));
        $content = is_array($data['content'] ?? null) ? $data['content'] : null;
        if ($content === null) {
            return null;
        }

        $mediaTypes = ['image', 'video', 'audio', 'document', 'sticker'];
        if (! in_array($messageType, $mediaTypes, true)) {
            return null;
        }

        $mime = strtolower(trim((string) ($content['mimeType'] ?? $content['mime_type'] ?? '')));
        $kind = match ($messageType) {
            'audio' => 'voice',
            'image', 'sticker' => 'image',
            default => $messageType,
        };

        $item = [
            'kind' => $kind,
            'mime_type' => $mime,
            'data_base64' => $content['data_base64'] ?? $content['base64'] ?? null,
            'url' => $content['mediaUrl'] ?? $content['media_url'] ?? null,
            'media_id' => $content['mediaId'] ?? $content['media_id'] ?? null,
            'filename' => $content['filename'] ?? $content['fileName'] ?? null,
        ];

        return $this->mapMediaItem($item);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function mapMediaItem(array $item): ?array
    {
        $kind = strtolower(trim((string) ($item['kind'] ?? $item['type'] ?? '')));
        $mime = strtolower(trim((string) ($item['mime_type'] ?? $item['mimeType'] ?? $item['mimetype'] ?? '')));
        $b64 = trim((string) ($item['data_base64'] ?? $item['base64'] ?? ''));
        $url = trim((string) ($item['url'] ?? $item['mediaUrl'] ?? ''));

        if ($kind === '' && $mime === '' && $b64 === '' && $url === '') {
            return null;
        }

        $out = [
            'kind' => $kind !== '' ? $kind : (str_starts_with($mime, 'audio/') ? 'voice' : 'image'),
            'mime_type' => $mime,
        ];

        if ($b64 !== '') {
            $out['data_base64'] = $b64;
        }

        if ($url !== '') {
            $out['url'] = $url;
        }

        $mediaId = trim((string) ($item['media_id'] ?? $item['mediaId'] ?? ''));
        if ($mediaId !== '') {
            $out['media_id'] = $mediaId;
        }

        $filename = trim((string) ($item['filename'] ?? $item['fileName'] ?? ''));
        if ($filename !== '') {
            $out['filename'] = $filename;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function inboundText(array $data): string
    {
        $text = trim((string) ($data['text'] ?? $data['body'] ?? $data['caption'] ?? ''));

        return $text;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function hasMedia(array $data): bool
    {
        return $this->extractMedia($data) !== null;
    }
}
