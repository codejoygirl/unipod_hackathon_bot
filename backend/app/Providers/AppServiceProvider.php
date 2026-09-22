<?php

namespace App\Providers;

use App\Contracts\Storage\PrivateStorage;
use App\Models\Community;
use App\Models\KnowledgeSource;
use App\Models\Tenant;
use App\Policies\CommunityPolicy;
use App\Policies\KnowledgeSourcePolicy;
use App\Policies\TenantPolicy;
use App\Services\Storage\LocalPrivateStorage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PrivateStorage::class, LocalPrivateStorage::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Tenant::class, TenantPolicy::class);
        Gate::policy(Community::class, CommunityPolicy::class);
        Gate::policy(KnowledgeSource::class, KnowledgeSourcePolicy::class);
    }
}
