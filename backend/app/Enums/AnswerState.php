<?php

declare(strict_types=1);

namespace App\Enums;

enum AnswerState: string
{
    case VERIFIED = 'VERIFIED';
    case POSSIBLE = 'POSSIBLE';
    case CONFLICT = 'CONFLICT';
    case INSUFFICIENT_EVIDENCE = 'INSUFFICIENT_EVIDENCE';
    case UNKNOWN = 'UNKNOWN';
    case BLOCKED = 'BLOCKED';

    public function isActionable(): bool
    {
        return match ($this) {
            self::VERIFIED, self::POSSIBLE, self::CONFLICT => true,
            self::INSUFFICIENT_EVIDENCE, self::UNKNOWN, self::BLOCKED => false,
        };
    }

    public function requiresAdminEscalation(): bool
    {
        return match ($this) {
            self::CONFLICT, self::INSUFFICIENT_EVIDENCE, self::UNKNOWN => true,
            self::VERIFIED, self::POSSIBLE, self::BLOCKED => false,
        };
    }
}
