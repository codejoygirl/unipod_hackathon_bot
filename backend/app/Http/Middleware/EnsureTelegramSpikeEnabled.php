<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTelegramSpikeEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('telegram_spike.enabled')) {
            abort(404, 'Telegram spike is disabled.');
        }

        $expected = (string) config('telegram_spike.shared_secret');
        $provided = (string) $request->header('X-Spike-Secret', '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            abort(401, 'Invalid spike secret.');
        }

        return $next($request);
    }
}
