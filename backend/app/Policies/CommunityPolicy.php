<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\Community;
use App\Models\Membership;
use App\Models\User;

class CommunityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->memberships()->whereNotNull('community_id')->exists();
    }

    public function view(User $user, Community $community): bool
    {
        return $user->belongsToCommunity($community->id)
            || Membership::query()
                ->where('tenant_id', $community->tenant_id)
                ->where('user_id', $user->id)
                ->where('role', MembershipRole::TenantOwner)
                ->exists();
    }

    public function create(User $user): bool
    {
        return Membership::query()
            ->where('user_id', $user->id)
            ->where('role', MembershipRole::TenantOwner)
            ->exists();
    }
}
