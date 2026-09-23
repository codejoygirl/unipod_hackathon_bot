<?php

declare(strict_types=1);

namespace App\Console\Concerns;

use App\Enums\MembershipRole;
use App\Models\Community;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Same tenant/community/user bootstrap as zak:seed-assistant-demo.
 *
 * @mixin Command
 */
trait EnsuresAssistantDemoScope
{
    /**
     * @return array{0: Tenant, 1: Community, 2: array{tenant: bool, community: bool}}
     */
    protected function ensureDemoTenantAndCommunity(): array
    {
        $created = ['tenant' => false, 'community' => false];

        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => 'demo-tenant'],
            ['name' => 'Demo Tenant'],
        );
        $created['tenant'] = $tenant->wasRecentlyCreated;

        $community = Community::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'demo-community'],
            [
                'name' => 'Demo Community',
                'description' => $this->defaultProgrammeCommunityDescription(),
            ],
        );
        $created['community'] = $community->wasRecentlyCreated;

        if ($community->description === null || trim((string) $community->description) === '') {
            $community->description = $this->defaultProgrammeCommunityDescription();
            $community->save();
        }

        return [$tenant, $community, $created];
    }

    /**
     * Channel spikes import into DEFAULT_COMMUNITY_ID when that community exists.
     */
    protected function communityForKnowledgeOperations(Community $demoCommunity): Community
    {
        foreach ($this->spikeDefaultCommunityIds() as $communityId) {
            $community = Community::query()->find($communityId);
            if ($community !== null) {
                return $community;
            }
        }

        return $demoCommunity;
    }

    /**
     * @return list<string>
     */
    private function spikeDefaultCommunityIds(): array
    {
        $ids = [
            trim((string) config('whatsapp_web_spike.default_community_id', '')),
            trim((string) config('telegram_spike.default_community_id', '')),
            trim((string) config('whatsapp_zavu.default_community_id', '')),
        ];

        return array_values(array_unique(array_filter($ids)));
    }

    protected function resolveDemoUserEmail(?string $optionEmail): string
    {
        if ($optionEmail !== null && trim($optionEmail) !== '') {
            return trim($optionEmail);
        }

        $whatsapp = trim((string) config('whatsapp_web_spike.default_user_email', ''));
        if ($whatsapp !== '') {
            return $whatsapp;
        }

        $telegram = trim((string) config('telegram_spike.default_user_email', ''));
        if ($telegram !== '') {
            return $telegram;
        }

        return 'demo@zak.test';
    }

    /**
     * @return array{0: User, 1: bool}
     */
    protected function ensureDemoAdminUser(Tenant $tenant, Community $community, string $email, string $password): array
    {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Demo Ask User',
                'password' => Hash::make($password),
            ],
        );
        $userCreated = $user->wasRecentlyCreated;

        Membership::query()->firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'community_id' => $community->id,
                'user_id' => $user->id,
                'role' => MembershipRole::CommunityAdmin,
            ],
        );

        return [$user, $userCreated];
    }

    protected function defaultProgrammeCommunityDescription(): string
    {
        return 'UniPods / Wadhwani programme community: schedules, sessions, modules, '
            .'deadlines, announcements, meeting notes, coaching, and session recordings or links '
            .'shared in the group.';
    }
}
