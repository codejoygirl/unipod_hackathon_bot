<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\AI\AiServiceClient;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->singleton(AiServiceClient::class, function ($app) {
            $config = $app['config']['ai_service'];

            return new AiServiceClient(
                http: $app->make(HttpFactory::class),
                baseUrl: rtrim($config['base_url'], '/'),
                hmacSecret: $config['hmac_secret'],
                timeout: (float) $config['timeout_seconds'],
                connectTimeout: (float) $config['connect_timeout_seconds'],
            );
        });
    }

    public function provides(): array
    {
        return [AiServiceClient::class];
    }
}