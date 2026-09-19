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

            $source->lifecycle_status = KnowledgeLifecycleStatus::Published;
            $source->published_at = now();
            $source->ai_source_id = $response['source_id'] ?? $response['data']['source_id'] ?? null;
            $source->ai_version_id = $response['version_id'] ?? $response['data']['version_id'] ?? null;
            $source->save();

            $this->audit->record('knowledge.published', $source->tenant_id, $source, [
                'ai_source_id' => $source->ai_source_id,
            ]);

            return $source->refresh();
        });
    }

    /**
     * WhatsApp export path: create a draft awaiting review (PRD §15 / Phase 2.10).
     *
     * @param  array<string, mixed>  $payload
     */
    public function importWhatsAppExport(User $user, array $payload): KnowledgeSource
    {
        return $this->createDraft($user, [
            'tenant_id' => $payload['tenant_id'],
            'community_id' => $payload['community_id'],
            'name' => $payload['name'] ?? 'WhatsApp export',
            'uri' => $payload['uri'] ?? ('whatsapp://export/'.(string) Str::ulid()),
            'source_type' => 'whatsapp',
            'authority_tier' => KnowledgeAuthorityTier::CommunityDiscussion,
            'language' => $payload['language'] ?? 'en',
            'content' => $payload['content'],
            'metadata' => array_merge($payload['metadata'] ?? [], [
                'channel' => 'whatsapp',
                'import_path' => 'export_review_workflow',
            ]),
        ]);
    }
}
