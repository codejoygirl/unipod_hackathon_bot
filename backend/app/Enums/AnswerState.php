<?php

declare(strict_types=1);

namespace App\Enums;

enum AnswerState: string
{
    case VERIFIED = 'VERIFIED';
    case POSSIBLE = 'POSSIBLE';
    case CONFLICT = 'CONFLICT';
    case INSUFFICIENT_EVIDENCE = 'INSUFFICIENT_EVIDENCE';

    public function isActionable(): bool
    {
        return match ($this) {
            self::VERIFIED, self::POSSIBLE, self::CONFLICT => true,
            self::INSUFFICIENT_EVIDENCE => false,
        };
    }

    public function requiresAdminEscalation(): bool
    {
        return match ($this) {
            self::CONFLICT, self::INSUFFICIENT_EVIDENCE => true,
            self::VERIFIED, self::POSSIBLE => false,
        };
    }
}