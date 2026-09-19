<?php

namespace Database\Factories;

use App\Enums\MembershipRole;
use App\Models\Community;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    protected $model = Membership::class;

    public function definition(): array
    {
        $tenant = Tenant::factory();

        return [
            'tenant_id' => $tenant,
            'community_id' => Community::factory()->state(fn (array $attributes) => [
                'tenant_id' => $attributes['tenant_id'] ?? $tenant,
            ]),
            'group_id' => null,
            'user_id' => User::factory(),
            'role' => MembershipRole::Member,
        ];
    }

    public function tenantOwner(): static
    {
        return $this->state(fn (): array => [
            'community_id' => null,
            'group_id' => null,
            'role' => MembershipRole::TenantOwner,
        ]);
    }

    public function forCommunity(Community $community, MembershipRole $role = MembershipRole::Member): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $community->tenant_id,
            'community_id' => $community->id,
            'role' => $role,
        ]);
    }
}
