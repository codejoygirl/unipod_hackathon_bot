<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\DTOs\Channels\InboundMessage;
use App\Models\Community;
use App\Models\User;
use App\Services\Knowledge\KnowledgeLifecycleService;
use App\Services\Knowledge\KnowledgeReplaceSuggestionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Admin /import from WhatsApp (Zavu, Web spike): pasted export text and/or attachments.
 */
final class WhatsAppKnowledgeImportService
{
    public function __construct(
        private readonly KnowledgeLifecycleService $lifecycle,
        private readonly AdminKnowledgeDesk $knowledgeDesk,
        private readonly KnowledgeReplaceSuggestionService $replaceSuggestions,
    ) {}

    public function isAdminImportWithAttachment(
        string $channelName,
        InboundMessage $message,
        ChannelCommandAccess $commandAccess,
        ChannelListenGate $listenGate,
    ): bool {
        if (! $listenGate->startsWithImportOrExport($message->text)) {
            return false;
        }

        if (! $commandAccess->isAdmin(
            $channelName,
            $message->externalUserId,
            array_merge(is_array($message->raw) ? $message->raw : [], ['text' => $message->text]),
        )) {
            return false;
        }

        return $this->inboundHasImportableAttachment($message);
    }

    public function inboundHasImportableAttachment(InboundMessage $message): bool
    {
        $raw = is_array($message->raw) ? $message->raw : [];
        $media = is_array($raw['media'] ?? null) ? $raw['media'] : null;
        if ($media === null) {
            return false;
        }

        $b64 = trim((string) ($media['data_base64'] ?? $media['base64'] ?? ''));

        return $b64 !== '';
    }

    public function adminImportFromInbound(
        string $channelName,
        InboundMessage $message,
        User $user,
        Community $community,
        string $uriPrefix,
    ): string {
        $afterPrefix = $this->stripImportPrefix(trim($message->text));
        $parsed = KnowledgeReplaceSuggestionService::parseImportMessage($afterPrefix);
        $body = $parsed['body'];
        $file = $this->extractUploadFromRaw($message->raw);

        if ($body === '' && $file === null) {
            return "Usage:\n"
                ."/import then paste a chat export, or\n"
                ."/import with a caption and attach a file (PDF, image, video, audio, .txt).\n\n"
                .'If it overlaps something already live, Zak lists IDs — you pick what to drop on publish.';
        }

        $name = $body !== ''
            ? $this->knowledgeDesk->suggestImportTitle($body)
            : ($file?->getClientOriginalName() ?? 'WhatsApp import');

        $payload = [
            'tenant_id' => $community->tenant_id,
            'community_id' => $community->id,
            'name' => $name,
            'uri' => $uriPrefix.Str::ulid(),
            'source_type' => 'whatsapp',
            'content' => $body,
            'metadata' => [
                'channel' => $channelName,
                'from' => $message->externalUserId,
                'origin' => 'admin_import',
            ],
        ];

        $source = $this->lifecycle->import($user, $payload, $file);

        Log::info('whatsapp_knowledge.import_draft', [
            'channel' => $channelName,
            'knowledge_id' => $source->id,
            'had_file' => $file !== null,
        ]);

        $fresh = $source->fresh() ?? $source;
        $suggestions = $this->replaceSuggestions->suggestForDraft($community, $fresh);
        if ($suggestions !== []) {
            $this->replaceSuggestions->persistSuggestions($fresh, $suggestions);

            return $this->knowledgeDesk->draftCreatedOverlapReply($fresh, $suggestions, 'whatsapp');
        }

        return $this->knowledgeDesk->draftCreatedReply($fresh, 'whatsapp');
    }

    private function stripImportPrefix(string $body): string
    {
        foreach (['/IMPORT', 'IMPORT', '/EXPORT', 'EXPORT'] as $prefix) {
            if (str_starts_with(strtoupper($body), $prefix)) {
                $rest = trim(substr($body, strlen($prefix)));

                return ltrim($rest, " \t\n\r\0\x0B:.-");
            }
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function extractUploadFromRaw(array $raw): ?UploadedFile
    {
        $media = is_array($raw['media'] ?? null) ? $raw['media'] : null;
        if ($media === null) {
            return null;
        }

        $b64 = trim((string) ($media['data_base64'] ?? $media['base64'] ?? ''));
        if (str_starts_with(strtolower($b64), 'data:') && str_contains($b64, ',')) {
            $b64 = trim(substr($b64, (int) strpos($b64, ',') + 1));
        }
        if ($b64 === '') {
            return null;
        }

        $decoded = base64_decode($b64, true);
        if ($decoded === false || $decoded === '') {
            return null;
        }

        $mime = strtolower(trim((string) ($media['mime_type'] ?? $media['mimetype'] ?? 'application/octet-stream')));
        $mime = explode(';', $mime, 2)[0];
        $filename = trim((string) ($media['filename'] ?? ''));
        if ($filename === '') {
            $filename = $this->defaultFilenameForMime($mime, (string) ($media['kind'] ?? ''));
        }

        $tmp = tempnam(sys_get_temp_dir(), 'wa_knowledge_');
        if ($tmp === false) {
            return null;
        }

        file_put_contents($tmp, $decoded);

        return new UploadedFile($tmp, $filename, $mime, null, true);
    }

    private function defaultFilenameForMime(string $mime, string $kind): string
    {
        $kind = strtolower(trim($kind));

        return match (true) {
            str_starts_with($mime, 'image/') => 'import.'.($mime === 'image/png' ? 'png' : 'jpg'),
            str_starts_with($mime, 'audio/') => 'import.m4a',
            str_starts_with($mime, 'video/') => 'import.mp4',
            $mime === 'application/pdf' => 'import.pdf',
            str_contains($mime, 'text') => 'import.txt',
            $kind === 'document' => 'import.pdf',
            default => 'import.bin',
        };
    }
}
