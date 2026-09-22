<?php

declare(strict_types=1);

namespace App\Services\Channels\Zavu;

/**
 * Verifies X-Zavu-Signature (HMAC-SHA256). Prefers v2 (t.body), falls back to v1 (body).
 *
 * @see https://docs.zavu.dev/guides/receiving-messages/security
 */
final class ZavuWebhookSignature
{
    public function verify(string $rawBody, ?string $header, string $secret, int $maxAgeSeconds = 300): bool
    {
        if ($header === null || $header === '' || $secret === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $piece) {
            $i = strpos($piece, '=');
            if ($i === false) {
                continue;
            }
            $parts[substr($piece, 0, $i)] = substr($piece, $i + 1);
        }

        if (! isset($parts['t']) || ! ctype_digit($parts['t'])) {
            return false;
        }

        $timestamp = (int) $parts['t'];
        $age = time() - $timestamp;
        if ($age > $maxAgeSeconds || $age < -60) {
            return false;
        }

        $received = $parts['v2'] ?? $parts['v1'] ?? null;
        if ($received === null || $received === '') {
            return false;
        }

        $signed = isset($parts['v2']) ? "{$timestamp}.{$rawBody}" : $rawBody;
        $expected = hash_hmac('sha256', $signed, $secret);

        return hash_equals($expected, $received);
    }

    /**
     * Build a valid X-Zavu-Signature header for tests.
     */
    public static function signV2(string $rawBody, string $secret, ?int $timestamp = null): string
    {
        $t = $timestamp ?? time();
        $v2 = hash_hmac('sha256', "{$t}.{$rawBody}", $secret);

        return "t={$t},v2={$v2}";
    }
}
