<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\KnowledgeSource;
use App\Models\Membership;
use App\Models\User;

class KnowledgeSourcePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->memberships()->exists();
    }

    public function view(User $user, KnowledgeSource $source): bool
    {
        return $user->belongsToCommunity($source->community_id)
            || $this->isTenantOwner($user, $source->tenant_id);
    }

    public function create(User $user): bool
    {
        return $user->memberships()->whereNotNull('community_id')->exists()
            || $user->memberships()->where('role', MembershipRole::TenantOwner)->exists();
    }

    public function submitForReview(User $user, KnowledgeSource $source): bool
    {
        return $this->view($user, $source)
            && $source->lifecycle_status->value === 'draft';
    }

    public function publish(User $user, KnowledgeSource $source): bool
    {
        if (! $this->view($user, $source)) {
            return false;
        }

        return $this->isPublisher($user, $source->tenant_id, $source->community_id);
    }

    public function reject(User $user, KnowledgeSource $source): bool
    {
        return $this->publish($user, $source);
    }

    private function isTenantOwner(User $user, string $tenantId): bool
    {
        return Membership::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('role', MembershipRole::TenantOwner)
            ->exists();
    }

    private function isPublisher(User $user, string $tenantId, string $communityId): bool
    {
        if ($this->isTenantOwner($user, $tenantId)) {
            return true;
        }

        return Membership::query()
            ->where('tenant_id', $tenantId)
            ->where('community_id', $communityId)
            ->where('user_id', $user->id)
            ->whereIn('role', [
                MembershipRole::CommunityAdmin,
                MembershipRole::TrustedOrganiser,
                MembershipRole::Moderator,
            ])
            ->exists();
    }
}
