<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class SpaStatic
{
    public static function file(string $relativePath = 'index.html'): BinaryFileResponse
    {
        $path = public_path($relativePath);
        if (! is_file($path)) {
            abort(503, 'Web UI is not published. On the server: cd frontend && npm ci && npm run build:laravel');
        }

        return response()->file($path);
    }
}
