<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\KnowledgeAuthorityTier;
use App\Enums\KnowledgeLifecycleStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeSource extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'knowledge_documents';

    protected $fillable = [
        'tenant_id',
        'community_id',
        'created_by',
        'name',
        'uri',
        'source_type',
        'authority_tier',
        'lifecycle_status',
        'language',
        'content',
        'content_sha256',
        'storage_path',
        'ai_source_id',
        'ai_version_id',
        'published_at',
        'effective_at',
        'expires_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'authority_tier' => KnowledgeAuthorityTier::class,
            'lifecycle_status' => KnowledgeLifecycleStatus::class,
            'metadata' => 'array',
            'published_at' => 'datetime',
            'effective_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPublished(): bool
    {
        return $this->lifecycle_status === KnowledgeLifecycleStatus::Published;
    }
}
