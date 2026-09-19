<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipRole;
use App\Models\Community;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_view_another_tenants_communities(): void
    {
        $tenantA = Tenant::factory()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $tenantB = Tenant::factory()->create(['name' => 'Tenant B', 'slug' => 'tenant-b']);

        $communityA = Community::factory()->create([
            'tenant_id' => $tenantA->id,
            'name' => 'Community A',
            'slug' => 'community-a',
        ]);
        $communityB = Community::factory()->create([
            'tenant_id' => $tenantB->id,
            'name' => 'Community B',
            'slug' => 'community-b',
        ]);

        $userA = User::factory()->create();
        Membership::factory()->forCommunity($communityA, MembershipRole::Member)->create([
            'user_id' => $userA->id,
        ]);

        $this->actingAs($userA);

        $this->getJson("/api/v1/tenants/{$tenantA->id}/communities")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $communityA->id);

        $this->getJson("/api/v1/tenants/{$tenantB->id}/communities")
            ->assertForbidden();

        $this->getJson("/api/v1/tenants/{$tenantB->id}/communities/{$communityB->id}")
            ->assertForbidden();

        $this->getJson("/api/v1/tenants/{$tenantA->id}/communities/{$communityB->id}")
            ->assertNotFound();
    }

    public function test_user_cannot_list_foreign_tenants(): void
    {
        $tenantA = Tenant::factory()->create(['slug' => 'alpha']);
        $tenantB = Tenant::factory()->create(['slug' => 'beta']);

        $user = User::factory()->create();
        Membership::factory()->tenantOwner()->create([
            'tenant_id' => $tenantA->id,
            'user_id' => $user->id,
        ]);

        $this->actingAs($user);

        $this->getJson('/api/v1/tenants')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $tenantA->id)
            ->assertJsonMissing(['id' => $tenantB->id]);

        $this->getJson("/api/v1/tenants/{$tenantB->id}")
            ->assertForbidden();
    }

    public function test_member_of_community_a_cannot_see_community_b_in_same_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $communityA = Community::factory()->create(['tenant_id' => $tenant->id, 'slug' => 'a']);
        $communityB = Community::factory()->create(['tenant_id' => $tenant->id, 'slug' => 'b']);

        $user = User::factory()->create();
        Membership::factory()->forCommunity($communityA)->create(['user_id' => $user->id]);

        $this->actingAs($user);

        $this->getJson("/api/v1/tenants/{$tenant->id}/communities")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $communityA->id);

        $this->getJson("/api/v1/tenants/{$tenant->id}/communities/{$communityB->id}")
            ->assertForbidden();
    }
}
