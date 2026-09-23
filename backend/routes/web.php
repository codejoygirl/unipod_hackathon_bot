<?php

use App\Support\SpaStatic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
| Web UI is a static Next export synced into public/ (npm run build:laravel).
| API, Sanctum, docs, and health stay on their normal routes.
*/

Route::get('/', fn (): BinaryFileResponse => SpaStatic::file('index.html'));

Route::fallback(function (Request $request): BinaryFileResponse {
    if ($request->is(
        'api',
        'api/*',
        'sanctum',
        'sanctum/*',
        'docs',
        'docs/*',
        'up',
        'storage',
        'storage/*',
    )) {
        abort(404);
    }

    $path = trim($request->path(), '/');
    if ($path === '') {
        return SpaStatic::file('index.html');
    }

    $nested = public_path($path.'/index.html');
    if (is_file($nested)) {
        return response()->file($nested);
    }

    $flat = public_path($path.'.html');
    if (is_file($flat)) {
        return response()->file($flat);
    }

    return SpaStatic::file('index.html');
});
