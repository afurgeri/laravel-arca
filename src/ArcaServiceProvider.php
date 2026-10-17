<?php

namespace Modules\Arca;

use Illuminate\Support\ServiceProvider;
use Modules\Arca\Console\Commands\AssignLegacyAccountCommand;
use Modules\Arca\Console\Commands\SyncPointOfSalesCommand;
use Modules\Arca\Contracts\ArcaClient;
use Modules\Arca\Contracts\ArcaStorage;
use Modules\Arca\Data\ArcaCredentials;
use Modules\Arca\Infrastructure\HttpSoapTransport;
use Modules\Arca\Infrastructure\LazyArcaClient;
use Modules\Arca\Infrastructure\Persistence\EloquentArcaStorage;
use Modules\Arca\Infrastructure\Persistence\MongoArcaStorage;
use Modules\Arca\Infrastructure\SoapTransport;
use MongoDB\Laravel\MongoDBServiceProvider;
use RuntimeException;

class ArcaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/arca.php', 'arca');
        $this->app->bind(ArcaStorage::class, function ($app): ArcaStorage {
            $driver = config('arca.storage_driver', 'eloquent');
            $connection = config('arca.connection') ?? config('database.default');
            $databaseDriver = config("database.connections.{$connection}.driver");

            if ($driver === 'mongodb') {
                if (! class_exists(MongoDBServiceProvider::class)) {
                    throw new RuntimeException('Install mongodb/laravel-mongodb ^5.11 to use ARCA MongoDB storage.');
                }

                if ($databaseDriver !== 'mongodb') {
                    throw new RuntimeException('ARCA MongoDB storage requires ARCA_DB_CONNECTION to select a MongoDB connection.');
                }

                return $app->make(MongoArcaStorage::class);
            }

            if ($driver !== 'eloquent' || $databaseDriver === 'mongodb') {
                throw new RuntimeException('Select a supported ARCA_STORAGE_DRIVER and matching ARCA_DB_CONNECTION.');
            }

            return $app->make(EloquentArcaStorage::class);
        });

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

        $migrationPath = config('arca.storage_driver', 'eloquent') === 'mongodb' ? 'mongodb' : 'migrations';
        $this->loadMigrationsFrom(__DIR__.'/../database/'.$migrationPath);
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        $this->publishes([
            __DIR__.'/../config/arca.php' => config_path('arca.php'),
        ], 'arca-config');
    }
}
