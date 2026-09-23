<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class FrontendDoctorCommand extends Command
{
    protected $signature = 'zak:frontend-doctor';

    protected $description = 'Check that the Next.js export exists in public/ (same-domain web chat UI)';

    public function handle(): int
    {
        $index = public_path('index.html');
        $next = public_path('_next');

        if (! is_file($index)) {
            $this->components->error('Missing backend/public/index.html');
            $this->line('Run from repo: cd frontend && npm ci && npm run build:laravel');

            return self::FAILURE;
        }

        if (! is_dir($next)) {
            $this->components->warn('index.html exists but public/_next/ is missing — UI assets may be broken.');
            $this->line('Re-run: cd frontend && npm run build:laravel');

            return self::FAILURE;
        }

        $this->components->info('Web UI static files look present.');
        $this->components->twoColumnDetail('index.html', $index);

        return self::SUCCESS;
    }
}
