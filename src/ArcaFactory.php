<?php

namespace Modules\Arca;

use Illuminate\Cache\CacheManager;
use Modules\Arca\Contracts\ArcaClient;
use Modules\Arca\Contracts\ArcaStorage;
use Modules\Arca\Data\ArcaCredentials;
use Modules\Arca\Exceptions\ArcaException;
use Modules\Arca\Infrastructure\AfipSdkClient;
use Modules\Arca\Infrastructure\SoapArcaClient;
use Modules\Arca\Infrastructure\SoapTransport;
use Modules\Arca\Infrastructure\WsaaAuthenticator;

class ArcaFactory
{
    public function __construct(private readonly ?SoapTransport $soapTransport = null) {}

    public function forCredentials(ArcaCredentials $credentials): ArcaManager
    {
        $driver = (string) config('arca.driver', 'soap');

        if ($driver === 'afipsdk') {
            if ($credentials->accessToken === null || $credentials->accessToken === '') {
                throw new ArcaException('The AfipSDK driver requires an AfipSDK access token.');
            }

            $client = new AfipSdkClient($credentials);
        } elseif ($driver === 'soap') {
            $client = $this->soapClient($credentials);
        } else {
            throw new ArcaException("Unsupported ARCA driver [{$driver}].");
        }

        return $this->manager($client, $credentials->cuit, $credentials->environment());
    }

    /** @param list<string>|null $allowedOperations */
    public function clientForCredentials(ArcaCredentials $credentials, ?array $allowedOperations = null): ArcaClient
    {
        $driver = (string) config('arca.driver', 'soap');

        if ($driver === 'afipsdk') {
            if ($allowedOperations !== null) {
                throw new ArcaException('Operation allowlists are supported only by the direct SOAP client.');
            }

            if ($credentials->accessToken === null || $credentials->accessToken === '') {
                throw new ArcaException('The AfipSDK driver requires an AfipSDK access token.');
            }

            return new AfipSdkClient($credentials);
        }

        if ($driver !== 'soap') {
            throw new ArcaException("Unsupported ARCA driver [{$driver}].");
        }

        return $this->soapClient($credentials, $allowedOperations);
    }

    public function productionSalesPointsClient(ArcaCredentials $credentials): ArcaClient
    {
        if (! $credentials->production) {
            throw new \InvalidArgumentException('The production points-of-sale validation requires production credentials.');
        }

        return $this->soapClient($credentials, ['FEParamGetPtosVenta']);
    }

    public function forClient(ArcaClient $client, string $cuit, string $environment): ArcaManager
    {
        if (preg_match('/^\d{11}$/D', $cuit) !== 1 || ! in_array($environment, ['testing', 'production'], true)) {
            throw new \InvalidArgumentException('A valid 11-digit CUIT and testing or production environment are required.');
        }

        return $this->manager($client, $cuit, $environment);
    }

    private function manager(ArcaClient $client, string $cuit, string $environment): ArcaManager
    {
        $mapper = new ArcaCodeMapper;
        $scope = new ArcaScope($cuit, $environment);
        $storage = app(ArcaStorage::class);

        return new ArcaManager(
            new PointOfSaleManager($client, $mapper, $scope, $storage),
            new VoucherAuthorizationManager($client, $mapper, $scope, $storage),
            $client,
        );
    }

    /** @param list<string>|null $allowedOperations */
    private function soapClient(ArcaCredentials $credentials, ?array $allowedOperations = null): SoapArcaClient
    {
        $environment = $credentials->environment();
        $endpoints = config("arca.endpoints.{$environment}", []);
        $certificate = $credentials->certificate ?? $this->readCredentialFile(
            config("arca.certificates.{$environment}.certificate_path") ?? config('arca.certificate_path'),
        );
        $privateKey = $credentials->privateKey ?? $this->readCredentialFile(
            config("arca.certificates.{$environment}.private_key_path") ?? config('arca.private_key_path'),
        );

        if ($certificate === null || $privateKey === null) {
            throw new ArcaException('Configure the ARCA certificate and private key for the selected environment.');
        }

        $soapCredentials = new ArcaCredentials(
            cuit: $credentials->cuit,
            production: $credentials->production,
            certificate: $certificate,
            privateKey: $privateKey,
        );
        $soapTransport = $this->soapTransport ?? app(SoapTransport::class);
        $authenticator = new WsaaAuthenticator(
            $soapTransport,
            app(CacheManager::class),
            (string) ($endpoints['wsaa'] ?? ''),
            config('arca.ticket_cache_store'),
            (int) config('arca.ticket_cache_safety_seconds', 300),
        );

        return new SoapArcaClient(
            $soapTransport,
            $authenticator,
            $soapCredentials,
            (string) ($endpoints['wsfe'] ?? ''),
            $allowedOperations,
        );
    }

    private function readCredentialFile(mixed $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new ArcaException('The configured ARCA certificate or private key could not be read.');
        }

        return $contents;
    }
}
