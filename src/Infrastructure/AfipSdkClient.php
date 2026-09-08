<?php

namespace Modules\Arca\Infrastructure;

use Afip;
use Modules\Arca\Contracts\ArcaClient;
use Modules\Arca\Exceptions\ArcaException;

class AfipSdkClient implements ArcaClient
{
    private Afip $client;

    public function __construct()
    {
        $options = [
            'CUIT' => (int) config('arca.cuit'),
            'access_token' => config('arca.access_token'),
            'production' => (bool) config('arca.production', false),
        ];

        $certificatePath = config('arca.certificate_path');
        $privateKeyPath = config('arca.private_key_path');

        if ($certificatePath !== null && $privateKeyPath !== null) {
            $certificate = file_get_contents((string) $certificatePath);
            $privateKey = file_get_contents((string) $privateKeyPath);

            if ($certificate === false || $privateKey === false) {
                throw new ArcaException('The ARCA certificate or private key could not be read.');
            }

            $options['cert'] = $certificate;
            $options['key'] = $privateKey;
        }

        $this->client = new Afip($options);
    }

    public function getLastVoucher(int $pointOfSale, int $voucherType): int
    {
        return (int) $this->client->ElectronicBilling->GetLastVoucher($pointOfSale, $voucherType);
    }

    public function getSalesPoints(): array
    {
        $response = $this->client->ElectronicBilling->GetSalesPoints();
        $encoded = json_encode($response);

        if ($encoded === false) {
            return [];
        }

        $normalized = json_decode($encoded, true);

        if (! is_array($normalized)) {
            return [];
        }

        return array_is_list($normalized) ? $normalized : [$normalized];
    }

    public function createVoucher(array $data): array
    {
        $response = $this->client->ElectronicBilling->CreateVoucher($data, true);

        $encoded = json_encode($response);

        if ($encoded === false) {
            return [];
        }

        $normalized = json_decode($encoded, true) ?? [];

        return is_array($normalized) ? $normalized : [];
    }
}
