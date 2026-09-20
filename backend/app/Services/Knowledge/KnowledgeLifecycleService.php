<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Enums\KnowledgeAuthorityTier;
use App\Enums\KnowledgeLifecycleStatus;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Services\AI\AiServiceClient;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class KnowledgeLifecycleService
{
    public function __construct(
        private readonly AiServiceClient $aiClient,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createDraft(User $user, array $data): KnowledgeSource
    {
        $content = (string) $data['content'];

        $source = KnowledgeSource::query()->create([
            'tenant_id' => $data['tenant_id'],
            'community_id' => $data['community_id'],
            'created_by' => $user->id,
            'name' => $data['name'],
            'uri' => $data['uri'] ?? ('knowledge://'.(string) Str::ulid()),
            'source_type' => $data['source_type'],
            'authority_tier' => $data['authority_tier'] ?? KnowledgeAuthorityTier::CommunityDiscussion,
            'lifecycle_status' => KnowledgeLifecycleStatus::Draft,
            'language' => $data['language'] ?? 'en',
            'content' => $content,
            'content_sha256' => hash('sha256', $content),
            'metadata' => $data['metadata'] ?? [],
            'effective_at' => $data['effective_at'] ?? now(),
        ]);

        $this->audit->record('knowledge.draft_created', $source->tenant_id, $source, [
            'community_id' => $source->community_id,
        ]);

        return $source;
    }

    public function submitForReview(User $user, KnowledgeSource $source): KnowledgeSource
    {
        $source->lifecycle_status = KnowledgeLifecycleStatus::PendingReview;
        $source->save();

        $this->audit->record('knowledge.submitted_for_review', $source->tenant_id, $source);

        return $source->refresh();
    }

    public function reject(User $user, KnowledgeSource $source, ?string $reason = null): KnowledgeSource
    {
        $source->lifecycle_status = KnowledgeLifecycleStatus::Rejected;
        $meta = $source->metadata ?? [];
        $meta['rejection_reason'] = $reason;
        $source->metadata = $meta;
        $source->save();

        $this->audit->record('knowledge.rejected', $source->tenant_id, $source, ['reason' => $reason]);

        return $source->refresh();
    }

    public function publish(User $user, KnowledgeSource $source): KnowledgeSource
    {
        return DB::transaction(function () use ($user, $source): KnowledgeSource {
            // File/multimodal imports may already be indexed in the AI service as pending.
            if ($source->ai_source_id !== null) {
                $this->aiClient->activateSource($source->ai_source_id);
            } else {
                $response = $this->aiClient->syncDocument(
                    tenantId: $source->tenant_id,
                    communityId: $source->community_id,
                    uri: $source->uri,
                    name: $source->name,
                    sourceType: $source->source_type,
                    content: $source->content,
                    authorityTier: $source->authority_tier->value,
                    metadata: array_merge($source->metadata ?? [], [
                        'lifecycle_status' => KnowledgeLifecycleStatus::Published->value,
                        'language' => $source->language,
                    ]),
                );

                $source->ai_source_id = $response['source_id'] ?? $response['data']['source_id'] ?? null;
                $source->ai_version_id = $response['version_id'] ?? $response['data']['version_id'] ?? null;
            }

            $source->lifecycle_status = KnowledgeLifecycleStatus::Published;
            $source->published_at = now();
            $source->save();

            $this->audit->record('knowledge.published', $source->tenant_id, $source, [
                'ai_source_id' => $source->ai_source_id,
            ]);

            return $source->refresh();
        });
    }

    /**
     * Unified import: text body and/or uploaded file → draft for review.
     *
     * @param  array<string, mixed>  $payload
     * @param  \Illuminate\Http\UploadedFile|null  $file
     */
    public function import(User $user, array $payload, $file = null): KnowledgeSource
    {
        $sourceType = (string) ($payload['source_type']
            ?? ($file ? $this->guessSourceTypeFromFile($file) : 'markdown'));

        $uri = (string) ($payload['uri'] ?? ('knowledge://import/'.(string) Str::ulid()));
        $name = (string) ($payload['name'] ?? ($file?->getClientOriginalName() ?? 'Imported knowledge'));
        $authority = $payload['authority_tier'] ?? KnowledgeAuthorityTier::CommunityDiscussion->value;
        $language = $payload['language'] ?? 'en';
        $metadata = array_merge($payload['metadata'] ?? [], [
            'import_path' => 'knowledge_import',
        ]);

        $content = (string) ($payload['content'] ?? '');
        $aiSourceId = null;
        $aiVersionId = null;

        if ($file !== null) {
            $response = $this->aiClient->ingestMultimodal(
                tenantId: $payload['tenant_id'],
                communityId: $payload['community_id'],
                uri: $uri,
                name: $name,
                sourceType: $sourceType,
                absoluteFilePath: $file->getRealPath(),
                originalFilename: $file->getClientOriginalName(),
                authorityTier: is_string($authority) ? $authority : $authority->value,
                mimeType: $file->getMimeType(),
            );
            $aiSourceId = $response['source_id'] ?? null;
            $aiVersionId = $response['version_id'] ?? null;
            $metadata['filename'] = $file->getClientOriginalName();
            $metadata['mime_type'] = $file->getMimeType();
            if ($content === '') {
                $content = sprintf(
                    'Multimodal import (%s): %s',
                    $sourceType,
                    $file->getClientOriginalName()
                );
            }
        } elseif ($content === '') {
            throw new \InvalidArgumentException('Import requires text content or a file.');
        }

        $source = $this->createDraft($user, [
            'tenant_id' => $payload['tenant_id'],
            'community_id' => $payload['community_id'],
            'name' => $name,
            'uri' => $uri,
            'source_type' => $sourceType,
            'authority_tier' => $authority,
            'language' => $language,
            'content' => $content,
            'metadata' => $metadata,
        ]);

        if ($aiSourceId !== null) {
            $source->ai_source_id = is_string($aiSourceId) ? $aiSourceId : (string) $aiSourceId;
            $source->ai_version_id = $aiVersionId !== null
                ? (is_string($aiVersionId) ? $aiVersionId : (string) $aiVersionId)
                : null;
            $source->save();
        }

        return $source->refresh();
    }

    /**
     * @param  \Illuminate\Http\UploadedFile  $file
     */
    private function guessSourceTypeFromFile($file): string
    {
        $mime = (string) $file->getMimeType();
        $ext = strtolower((string) $file->getClientOriginalExtension());

        return match (true) {
            str_starts_with($mime, 'image/') || in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) => 'image',
            str_starts_with($mime, 'audio/') || in_array($ext, ['mp3', 'wav', 'm4a', 'ogg', 'flac'], true) => 'audio',
            str_starts_with($mime, 'video/') || in_array($ext, ['mp4', 'mov', 'webm', 'mkv'], true) => 'video',
            in_array($ext, ['txt', 'md', 'markdown'], true) => 'markdown',
            default => 'text',
        };
    }
}
