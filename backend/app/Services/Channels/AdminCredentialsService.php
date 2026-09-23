<?php

declare(strict_types=1);

namespace App\Services\Channels;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class AdminCredentialsService
{
    /**
     * @return list<array{
     *     name: string,
     *     phone: string,
     *     display_phone: string,
     *     password: string
     * }>
     */
    public function allAdminLogins(): array
    {
        $raw = (string) config('zak_web_chat.admin_logins', env('ZAK_ADMIN_LOGINS', ''));

        $admins = [];
        if (trim($raw) !== '') {
            foreach (explode(',', $raw) as $entry) {
                $parts = explode(':', trim($entry));
                if (count($parts) >= 3) {
                    $name = trim($parts[0]);
                    $phoneRaw = trim($parts[1]);
                    $password = trim($parts[2]);
                    $digits = preg_replace('/\D+/', '', $phoneRaw) ?? '';
                    if ($digits !== '' && $password !== '') {
                        $admins[] = [
                            'name' => $name,
                            'phone' => $digits,
                            'display_phone' => $this->formatDisplayPhone($phoneRaw, $digits),
                            'password' => $password,
                        ];
                    }
                }
            }
        }

        if ($admins === []) {
            $admins = [
                ['name' => 'Diane', 'phone' => '250783188655', 'display_phone' => '+250 783 188 655', 'password' => 'Zak!Dia#8318'],
                ['name' => 'Gift Ntuli', 'phone' => '263774094822', 'display_phone' => '+263 77 409 4822', 'password' => 'Zak!Gft#7409'],
                ['name' => 'Jeovaire umukundwa', 'phone' => '250789355992', 'display_phone' => '+250 789 355 992', 'password' => 'Zak!Jeo#8935'],
                ['name' => 'Munira', 'phone' => '250786387244', 'display_phone' => '+250 786 387 244', 'password' => 'Zak!Mun#7863'],
                ['name' => 'Charles Bolton', 'phone' => '27793565520', 'display_phone' => '+27 79 356 5520', 'password' => 'Zak!Chr#7935'],
                ['name' => 'Lead Admin', 'phone' => '2347041131371', 'display_phone' => '+234 704 113 1371', 'password' => 'Zak!Adm#7041'],
                ['name' => 'Telegram Admin', 'phone' => '2348117084647', 'display_phone' => '+234 811 708 4647', 'password' => 'Zak!Spk#8117'],
                ['name' => 'Operations Admin', 'phone' => '2349137374124', 'display_phone' => '+234 913 737 4124', 'password' => 'Zak!Ops#9137'],
            ];
        }

        return $admins;
    }

    /**
     * @return list<string>
     */
    public function adminPhones(): array
    {
        return array_values(array_unique(array_map(
            fn (array $a) => $a['phone'],
            $this->allAdminLogins()
        )));
    }

    public function isAdminPhone(string $phone): bool
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return false;
        }

        foreach ($this->allAdminLogins() as $admin) {
            if ($this->phonesMatch($digits, $admin['phone'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{name: string, phone: string, display_phone: string, password: string}|null
     */
    public function findAdminByPhone(string $phone): ?array
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }

        foreach ($this->allAdminLogins() as $admin) {
            if ($this->phonesMatch($digits, $admin['phone'])) {
                return $admin;
            }
        }

        return null;
    }

    public function verifyPassword(string $phone, string $password): bool
    {
        $admin = $this->findAdminByPhone($phone);
        if ($admin === null) {
            return false;
        }

        return hash_equals($admin['password'], trim($password));
    }

    public function issueAdminToken(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $token = 'zak_adm_'.Str::random(32);
        Cache::put('zak_admin_token:'.$token, $digits, now()->addDays(30));

        return $token;
    }

    public function verifyAdminToken(string $phone, ?string $token): bool
    {
        if ($token === null || trim($token) === '') {
            return false;
        }

        $cachedPhone = Cache::get('zak_admin_token:'.trim($token));
        if (! is_string($cachedPhone) || $cachedPhone === '') {
            return false;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return $this->phonesMatch($digits, $cachedPhone);
    }

    public function formatLoginsCard(string $style = 'whatsapp'): string
    {
        $admins = $this->allAdminLogins();
        $isWa = $style === 'whatsapp';

        $title = $isWa
            ? "*Community Admin Web Logins*\n_Used for web chat login only_"
            : "Community Admin Web Logins\nUsed for web chat login only";

        $lines = [$title];

        foreach ($admins as $admin) {
            $name = $admin['name'];
            $phone = $admin['display_phone'];
            $pass = $admin['password'];

            if ($isWa) {
                $lines[] = "• *{$name}*\n  Phone: {$phone}\n  Password: `{$pass}`";
            } else {
                $lines[] = "• {$name}\n  Phone: {$phone}\n  Password: {$pass}";
            }
        }

        $note = $isWa
            ? "_Note: Passwords are only required for admin web login. Normal members log in with phone only._"
            : "Note: Passwords are only required for admin web login. Normal members log in with phone only.";

        $lines[] = $note;

        return implode("\n\n", $lines);
    }

    private function formatDisplayPhone(string $raw, string $digits): string
    {
        if (str_contains($raw, ' ')) {
            return str_starts_with($raw, '+') ? $raw : '+'.$raw;
        }

        // Add standard international prefix
        return '+'.$digits;
    }

    private function phonesMatch(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        $aCore = ltrim($a, '0');
        $bCore = ltrim($b, '0');
        if ($aCore !== '' && $aCore === $bCore) {
            return true;
        }

        $minLen = min(strlen($aCore), strlen($bCore));
        if ($minLen >= 9 && (str_ends_with($aCore, $bCore) || str_ends_with($bCore, $aCore))) {
            return true;
        }

        return false;
    }
}
