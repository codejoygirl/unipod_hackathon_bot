<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

final class GenerateVapidKeysCommand extends Command
{
    protected $signature = 'zak:vapid-generate';

    protected $description = 'Generate VAPID keys for web push (paste into backend/.env)';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();
        $this->line('Add these to backend/.env (and keep private key secret):');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('VAPID_SUBJECT=mailto:abdulsamadbalogun25@gmail.com');
        $this->newLine();

        return self::SUCCESS;
    }
}
