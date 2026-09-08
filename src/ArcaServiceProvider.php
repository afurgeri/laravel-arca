<?php

namespace Modules\Arca;

use Illuminate\Support\ServiceProvider;
use Modules\Arca\Contracts\ArcaClient;
use Modules\Arca\Infrastructure\AfipSdkClient;

class ArcaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/arca.php', 'arca');

        $this->app->singleton(ArcaClient::class, AfipSdkClient::class);
        $this->app->singleton(ArcaManager::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        $this->publishes([
            __DIR__.'/../config/arca.php' => config_path('arca.php'),
        ], 'arca-config');
    }
}
