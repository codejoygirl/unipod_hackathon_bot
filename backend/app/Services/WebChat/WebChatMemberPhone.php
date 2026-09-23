<?php

declare(strict_types=1);

namespace App\Services\WebChat;

final class WebChatMemberPhone
{
    public function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', trim($raw)) ?? '';
        if ($digits === '') {
            return null;
        }

        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }

        return $digits;
    }

    public function sessionIdFromPhone(string $phoneDigits): string
    {
        return 'm'.$phoneDigits;
    }

    public function displayLabel(string $phoneDigits): string
    {
        return '+'.$phoneDigits;
    }
}
