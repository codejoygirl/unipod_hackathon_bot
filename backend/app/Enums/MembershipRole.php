<?php

namespace App\Enums;

enum MembershipRole: string
{
    case TenantOwner = 'tenant_owner';
    case CommunityAdmin = 'community_admin';
    case TrustedOrganiser = 'trusted_organiser';
    case Moderator = 'moderator';
    case Member = 'member';
    case Guest = 'guest';

    public function isTenantLevel(): bool
    {
        return $this === self::TenantOwner;
    }
}
