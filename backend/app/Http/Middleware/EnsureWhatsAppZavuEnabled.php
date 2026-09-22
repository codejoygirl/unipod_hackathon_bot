<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureWhatsAppZavuEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('whatsapp_zavu.enabled')) {
            abort(404, 'WhatsApp Zavu channel is disabled.');
        }

        return $next($request);
    }
}
