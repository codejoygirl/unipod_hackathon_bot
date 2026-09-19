<?php

declare(strict_types=1);

namespace App\Enums;

enum KnowledgeLifecycleStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Published = 'published';
    case Superseded = 'superseded';
    case Archived = 'archived';
    case Rejected = 'rejected';

    public function isRetrievable(): bool
    {
        return $this === self::Published;
    }
}
