<?php

namespace Modules\Arca;

use Modules\Arca\Contracts\ArcaClient;
use Modules\Arca\Data\ArcaCredentials;
use Modules\Arca\Infrastructure\AfipSdkClient;

class ArcaFactory
{
    public function forCredentials(ArcaCredentials $credentials): ArcaManager
    {
        $client = new AfipSdkClient($credentials);

        return $this->manager($client, $credentials->cuit, $credentials->environment());
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

        return new ArcaManager(
            new PointOfSaleManager($client, $mapper, $scope),
            new VoucherAuthorizationManager($client, $mapper, $scope),
        );
    }
}
