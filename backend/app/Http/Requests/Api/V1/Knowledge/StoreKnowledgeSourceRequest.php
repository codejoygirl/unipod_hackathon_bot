<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Knowledge;

use App\Enums\KnowledgeAuthorityTier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreKnowledgeSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'ulid', 'exists:tenants,id'],
            'community_id' => ['required', 'ulid', 'exists:communities,id'],
            'name' => ['required', 'string', 'max:255'],
            'uri' => ['nullable', 'string', 'max:1024'],
            'source_type' => ['required', 'string', 'max:50'],
            'content' => ['required', 'string', 'min:1'],
            'authority_tier' => ['nullable', Rule::enum(KnowledgeAuthorityTier::class)],
            'language' => ['nullable', 'string', 'max:10'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
