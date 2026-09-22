<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\KnowledgeSource */
class KnowledgeSourceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'community_id' => $this->community_id,
            'name' => $this->name,
            'uri' => $this->uri,
            'source_type' => $this->source_type,
            'authority_tier' => $this->authority_tier?->value,
            'lifecycle_status' => $this->lifecycle_status?->value,
            'language' => $this->language,
            'ai_source_id' => $this->ai_source_id,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
