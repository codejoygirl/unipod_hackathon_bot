<?php

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Zak API routes (documented by Scramble under /docs/api)
|--------------------------------------------------------------------------
|
| Prefix: /api  (added by Laravel)
| Version: /v1
|
*/

Route::prefix('v1')->group(function (): void {
    Route::get('/health/live', [HealthController::class, 'live'])
        ->name('api.v1.health.live');
});
