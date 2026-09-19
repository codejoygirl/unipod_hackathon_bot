<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Knowledge;

use Illuminate\Foundation\Http\FormRequest;

class ImportWhatsAppKnowledgeRequest extends FormRequest
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
            'content' => ['required', 'string', 'min:1'],
            'name' => ['nullable', 'string', 'max:255'],
            'uri' => ['nullable', 'string', 'max:1024'],
            'language' => ['nullable', 'string', 'max:10'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
