<?php

namespace Modules\Arca\Infrastructure;

use Modules\Arca\Contracts\ArcaAuthorizer;
use Modules\Arca\Contracts\ArcaClient;
use Modules\Arca\Data\ArcaCredentials;
use Modules\Arca\Exceptions\ArcaException;

class SoapArcaClient implements ArcaClient
{
    private const NAMESPACE = 'http://ar.gov.afip.dif.FEV1/';

    /** @param list<string>|null $allowedOperations */
    public function __construct(
        private readonly SoapTransport $transport,
        private readonly ArcaAuthorizer $authenticator,
        private readonly ArcaCredentials $credentials,
        private readonly string $endpoint,
        private readonly ?array $allowedOperations = null,
    ) {}

    public function getSalesPoints(): array
    {
        $response = $this->invoke('FEParamGetPtosVenta', ['Auth' => $this->auth()]);
        $points = $this->list(data_get($response, 'ResultGet.PtoVenta'));

        return array_map(static function (array $point): array {
            $blocked = $point['Bloqueado'] ?? false;
            $point['Bloqueado'] = in_array($blocked, [true, 'S', 'Y', '1', 1], true);

            if (strtoupper((string) ($point['FchBaja'] ?? '')) === 'NULL') {
                $point['FchBaja'] = null;
            }

            return $point;
        }, $points);
    }

    public function getVoucherTypes(): array
    {
        $response = $this->invoke('FEParamGetTiposCbte', ['Auth' => $this->auth()]);

        return $this->list(data_get($response, 'ResultGet.CbteTipo'));
    }

    public function getLastVoucher(int $pointOfSale, int $voucherType): int
    {
        $response = $this->invoke('FECompUltimoAutorizado', [
            'Auth' => $this->auth(),
            'PtoVta' => $pointOfSale,
            'CbteTipo' => $voucherType,
        ]);
        $number = $response['CbteNro'] ?? null;

        if (! is_numeric($number) || (int) $number < 0) {
            throw new ArcaException('ARCA returned an invalid last authorized voucher number.');
        }

        return (int) $number;
    }

    public function getVoucherInfo(int $number, int $pointOfSale, int $voucherType): ?array
    {
        try {
            $response = $this->invoke('FECompConsultar', [
                'Auth' => $this->auth(),
                'FeCompConsReq' => ['CbteTipo' => $voucherType, 'CbteNro' => $number, 'PtoVta' => $pointOfSale],
            ]);
        } catch (ArcaException $exception) {
            $errors = $exception->context['errors'] ?? [];
            $errors = is_array($errors) && array_is_list($errors) ? $errors : [$errors];
            $notFound = collect($errors)->contains(static fn (mixed $error): bool => is_array($error) && (int) ($error['Code'] ?? 0) === 602
            );

            if ($notFound) {
                return null;
            }

            throw $exception;
        }

        $result = data_get($response, 'ResultGet');

        if ($result === null) {
            return null;
        }

        return is_array($result) ? $result : null;
    }

    public function createVoucher(array $data): array
    {
        $header = [
            'CantReg' => (int) ($data['CantReg'] ?? 1),
            'PtoVta' => (int) $data['PtoVta'],
            'CbteTipo' => (int) $data['CbteTipo'],
        ];
        $detail = $data;
        unset($detail['CantReg'], $detail['PtoVta'], $detail['CbteTipo']);

        if (isset($detail['Iva'])) {
            $detail['Iva'] = ['AlicIva' => $detail['Iva']];
        }

        if (isset($detail['CbtesAsoc'])) {
            $detail['CbtesAsoc'] = ['CbteAsoc' => $detail['CbtesAsoc']];
        }

        $response = $this->invoke('FECAESolicitar', [
            'Auth' => $this->auth(),
            'FeCAEReq' => [
                'FeCabReq' => $header,
                'FeDetReq' => ['FECAEDetRequest' => $detail],
            ],
        ]);

        return [
            'FeCabResp' => $response['FeCabResp'] ?? null,
            'FeDetResp' => $response['FeDetResp'] ?? null,
            'Events' => $response['Events']['Evt'] ?? null,
            'Errors' => $response['Errors']['Err'] ?? null,
        ];
    }

    /** @return array{Token: string, Sign: string, Cuit: int} */
    private function auth(): array
    {
        return [...$this->authenticator->authorization($this->credentials), 'Cuit' => (int) $this->credentials->cuit];
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function invoke(string $operation, array $arguments): array
    {
        if ($this->allowedOperations !== null && ! in_array($operation, $this->allowedOperations, true)) {
            throw new ArcaException("ARCA operation {$operation} is blocked by the active validation allowlist.");
        }

        $response = $this->transport->call($this->endpoint, self::NAMESPACE, $operation, $arguments);
        $errors = data_get($response, 'Errors.Err');

        if ($errors !== null && $errors !== []) {
            $errors = is_array($errors) && array_is_list($errors) ? $errors : [$errors];
            $messages = array_map(static function (mixed $error): string {
                if (! is_array($error)) {
                    return 'ARCA returned an unspecified error.';
                }

                return '['.($error['Code'] ?? '?').'] '.($error['Msg'] ?? 'ARCA returned an unspecified error.');
            }, $errors);

            throw new ArcaException(implode('; ', $messages), ['errors' => $errors]);
        }

        return $response;
    }

    /** @return list<array<string, mixed>> */
    private function list(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        if (array_is_list($value)) {
            return array_values(array_filter($value, 'is_array'));
        }

        return [$value];
    }
}
