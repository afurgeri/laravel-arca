<?php

namespace Modules\Arca\Infrastructure;

use Afip;
use Modules\Arca\Contracts\ArcaClient;
use Modules\Arca\Data\ArcaCredentials;
use Modules\Arca\Exceptions\ArcaException;

class AfipSdkClient implements ArcaClient
{
    private Afip $client;

    public function __construct(?ArcaCredentials $credentials = null)
    {
        $options = [
            'CUIT' => (int) ($credentials === null ? config('arca.cuit') : $credentials->cuit),
            'access_token' => $credentials === null ? config('arca.access_token') : $credentials->accessToken,
            'production' => $credentials === null ? (bool) config('arca.production', false) : $credentials->production,
        ];

        $certificatePath = $credentials === null ? config('arca.certificate_path') : null;
        $privateKeyPath = $credentials === null ? config('arca.private_key_path') : null;

        if ($credentials?->certificate !== null && $credentials->privateKey !== null) {
            $options['cert'] = $credentials->certificate;
            $options['key'] = $credentials->privateKey;
        }

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

    public function getVoucherInfo(int $number, int $pointOfSale, int $voucherType): ?array
    {
        $billing = $this->client->ElectronicBilling;

        if (! method_exists($billing, 'GetVoucherInfo')) {
            throw new ArcaException('The ARCA SDK does not support voucher lookup.');
        }

        $response = $billing->GetVoucherInfo($number, $pointOfSale, $voucherType);

        if ($response === null) {
            return null;
        }

        $normalized = json_decode((string) json_encode($response), true);

        return is_array($normalized) ? $normalized : null;
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

    public function getVoucherTypes(): array
    {
        $billing = $this->client->ElectronicBilling;

        if (! method_exists($billing, 'GetVoucherTypes')) {
            throw new ArcaException('The ARCA SDK does not support voucher type lookup.');
        }

        $response = $billing->GetVoucherTypes();
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
