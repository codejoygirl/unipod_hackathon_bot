<?php

namespace App\Http\Requests\Api\V1\Communities;

use App\Enums\MembershipRole;
use App\Models\Membership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreCommunityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenantId = (string) $this->route('tenant');

        return Membership::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $this->user()?->id)
            ->where('role', MembershipRole::TenantOwner)
            ->exists();
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('slug') && $this->filled('name')) {
            $this->merge([
                'slug' => Str::slug((string) $this->input('name')),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = (string) $this->route('tenant');

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('communities', 'slug')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
        ];
    }
}
