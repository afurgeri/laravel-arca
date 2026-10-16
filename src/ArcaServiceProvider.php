<?php

namespace Modules\Arca;

use Illuminate\Support\ServiceProvider;
use Modules\Arca\Console\Commands\AssignLegacyAccountCommand;
use Modules\Arca\Console\Commands\SyncPointOfSalesCommand;
use Modules\Arca\Contracts\ArcaClient;
use Modules\Arca\Data\ArcaCredentials;
use Modules\Arca\Infrastructure\HttpSoapTransport;
use Modules\Arca\Infrastructure\LazyArcaClient;
use Modules\Arca\Infrastructure\SoapTransport;

class ArcaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/arca.php', 'arca');

        $this->app->singleton(HttpSoapTransport::class, fn (): HttpSoapTransport => new HttpSoapTransport(
            (int) config('arca.timeout', 20),
            (int) config('arca.connect_timeout', 5),
            config('arca.ssl_cipher_list', 'ECDHE+AESGCM'),
        ));
        $this->app->singleton(SoapTransport::class, HttpSoapTransport::class);
        $this->app->singleton(ArcaClient::class, function ($app): ArcaClient {
            return new LazyArcaClient(function () use ($app): ArcaClient {
                $credentials = new ArcaCredentials(
                    cuit: (string) config('arca.cuit', ''),
                    accessToken: config('arca.access_token'),
                    production: (bool) config('arca.production', false),
                );

                return $app->make(ArcaFactory::class)->clientForCredentials($credentials);
            });
        });
        $this->app->singleton(ArcaScope::class, fn (): ArcaScope => new ArcaScope(
            config('arca.cuit') === null ? null : (string) config('arca.cuit'),
            config('arca.cuit') === null ? null : ((bool) config('arca.production', false) ? 'production' : 'testing'),
        ));
        $this->app->singleton(ArcaFactory::class);
        $this->app->singleton(ArcaManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SyncPointOfSalesCommand::class, AssignLegacyAccountCommand::class]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        $this->publishes([
            __DIR__.'/../config/arca.php' => config_path('arca.php'),
        ], 'arca-config');
    }
}
