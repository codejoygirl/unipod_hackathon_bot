<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'tenants' => $this->whenLoaded('memberships', function () {
                return $this->memberships
                    ->groupBy('tenant_id')
                    ->map(function ($memberships, $tenantId) {
                        $tenant = $memberships->first()->tenant;

                        return [
                            'id' => $tenantId,
                            'name' => $tenant?->name,
                            'slug' => $tenant?->slug,
                            'roles' => $memberships->pluck('role')->map(fn ($role) => $role->value)->unique()->values(),
                            'community_ids' => $memberships->pluck('community_id')->filter()->unique()->values(),
                        ];
                    })
                    ->values();
            }),
        ];
    }
}
