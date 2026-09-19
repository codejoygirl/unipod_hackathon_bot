<?php

declare(strict_types=1);

namespace App\Enums;

enum KnowledgeAuthorityTier: string
{
    case OfficialAnnouncement = 'official_announcement';
    case PolicyDocument = 'policy_document';
    case VerifiedResource = 'verified_resource';
    case CommunityDiscussion = 'community_discussion';
}
