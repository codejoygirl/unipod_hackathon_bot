<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Knowledge;

use App\Enums\KnowledgeAuthorityTier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ImportKnowledgeRequest extends FormRequest
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
            // Plain-text body (WhatsApp export, markdown, notes). Omit when uploading a file.
            'content' => ['nullable', 'string', 'min:1'],
            // Uploaded media or text file. Omit when sending `content` as text.
            'file' => ['nullable', 'file', 'max:51200'], // 50 MB
            'name' => ['nullable', 'string', 'max:255'],
            'uri' => ['nullable', 'string', 'max:1024'],
            'source_type' => [
                'nullable',
                'string',
                Rule::in([
                    'markdown',
                    'text',
                    'whatsapp',
                    'transcript',
                    'pdf',
                    'docx',
                    'image',
                    'audio',
                    'video',
                ]),
            ],
            'language' => ['nullable', 'string', 'max:10'],
            'authority_tier' => ['nullable', Rule::enum(KnowledgeAuthorityTier::class)],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasContent = filled($this->input('content'));
            $hasFile = $this->hasFile('file');

            if (! $hasContent && ! $hasFile) {
                $validator->errors()->add('content', 'Provide either content (text) or a file.');
            }
        });
    }
}
