<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Scramble OpenAPI UI: /docs/api  JSON: /docs/api.json (local by default)
        // Sanctum security schemes will be registered when auth is wired (Phase 1).
    }
}
