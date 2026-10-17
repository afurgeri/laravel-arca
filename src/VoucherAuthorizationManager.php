<?php

namespace Modules\Arca;

use DateTimeImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Arca\Contracts\ArcaClient;
use Modules\Arca\Contracts\ArcaStorage;
use Modules\Arca\Data\AuthorizationResult;
use Modules\Arca\Data\IssueVoucherData;
use Modules\Arca\Enums\Currency;
use Modules\Arca\Enums\VoucherLetter;
use Modules\Arca\Enums\VoucherOperation;
use Modules\Arca\Enums\VoucherStatus;
use Modules\Arca\Models\Voucher;
use RuntimeException;
use Throwable;

class VoucherAuthorizationManager
{
    public function __construct(
        private readonly ArcaClient $arca,
        private readonly ArcaCodeMapper $mapper,
        private readonly ArcaScope $scope,
        private readonly ArcaStorage $storage,
    ) {}

    public function authorize(IssueVoucherData $data): AuthorizationResult
    {
        $existing = $data->idempotencyKey === null
            ? null
            : $this->storage->findIdempotentVoucher($this->scope, $data->idempotencyKey);

        if ($existing !== null) {
            if (in_array($existing->status, [VoucherStatus::Pending->value, VoucherStatus::Unknown->value, VoucherStatus::Rejected->value], true) && $existing->number !== null) {
                $this->reconcileVoucher($existing, $data->pointOfSale);
            }

            return new AuthorizationResult($existing);
        }

        $point = $this->storage->activePoint($this->scope, $data->pointOfSale);
        $voucherType = $this->mapper->voucherType($data->letter, $data->operation);
        $lastAuthorized = $this->lastAuthorizedVoucher($data->pointOfSale, $voucherType);
        $occupied = $this->storage->occupiedVoucher($point, $voucherType, $lastAuthorized + 1);

        if ($occupied !== null && in_array($occupied->status, [VoucherStatus::Rejected->value, VoucherStatus::Unknown->value], true)) {
            $this->reconcileVoucher($occupied, $data->pointOfSale);

            if ($occupied->status === VoucherStatus::Authorized->value) {
                throw new RuntimeException("ARCA voucher {$occupied->number} was already authorized. Synchronize the point of sale before issuing another voucher.");
            }
        }

        $reservation = $this->storage->reserveVoucher($this->scope, $data, $voucherType, $lastAuthorized, $occupied);
        $voucher = $reservation->voucher;

        if (! $reservation->created) {
            return new AuthorizationResult($voucher);
        }

        $payload = $this->payload($data, $voucher->number, $voucherType);
        $correlationId = (string) Str::uuid();
        $startedAt = microtime(true);

        try {
            $response = $this->arca->createVoucher($payload);
            $duration = (int) round((microtime(true) - $startedAt) * 1000);
            $cae = data_get($response, 'FeDetResp.FECAEDetResponse.CAE') ?? ($response['CAE'] ?? null);
            $caeExpiresAt = data_get($response, 'FeDetResp.FECAEDetResponse.CAEFchVto') ?? ($response['CAEFchVto'] ?? null);
            $authorized = $cae !== null && (string) $cae !== '';
            $status = $authorized ? VoucherStatus::Authorized->value : VoucherStatus::Rejected->value;
            $errorMessage = $authorized ? null : $this->responseMessage($response);

            $this->storage->recordOperation($this->scope, [
                'voucher_id' => $voucher->id,
                'correlation_id' => $correlationId,
                'method' => 'ElectronicBilling.CreateVoucher',
                'status' => $status,
                'duration_ms' => $duration,
                'request' => $payload,
                'response' => $response,
                'error_message' => $errorMessage,
            ]);

            $this->storage->updateVoucher($voucher, [
                'status' => $status,
                'cae' => $authorized ? (string) $cae : null,
                'cae_expires_at' => $authorized ? $this->normalizeCaeExpirationDate($caeExpiresAt) : null,
                'arca_response' => $response,
                'error_message' => $errorMessage,
            ]);
        } catch (Throwable $exception) {
            $this->storage->recordOperation($this->scope, [
                'voucher_id' => $voucher->id,
                'correlation_id' => $correlationId,
                'method' => 'ElectronicBilling.CreateVoucher',
                'status' => VoucherStatus::Unknown->value,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'request' => $payload,
                'error_message' => $exception->getMessage(),
            ]);

            $this->storage->updateVoucher($voucher, [
                'status' => VoucherStatus::Unknown->value,
                'error_message' => $exception->getMessage(),
            ]);
        }

        return new AuthorizationResult($voucher->refresh());
    }

    private function lastAuthorizedVoucher(int $pointOfSale, int $voucherType): int
    {
        $startedAt = microtime(true);
        $correlationId = (string) Str::uuid();

        try {
            $last = $this->arca->getLastVoucher($pointOfSale, $voucherType);

            $this->storage->recordOperation($this->scope, [
                'correlation_id' => $correlationId,
                'method' => 'ElectronicBilling.GetLastVoucher',
                'status' => 'successful',
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'request' => ['point_of_sale' => $pointOfSale, 'voucher_type' => $voucherType],
                'response' => ['last_voucher' => $last],
            ]);

            return $last;
        } catch (Throwable $exception) {
            $this->storage->recordOperation($this->scope, [
                'correlation_id' => $correlationId,
                'method' => 'ElectronicBilling.GetLastVoucher',
                'status' => 'failed',
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'request' => ['point_of_sale' => $pointOfSale, 'voucher_type' => $voucherType],
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function reconcileVoucher(Voucher $voucher, int $pointOfSale): void
    {
        if ($voucher->number === null) {
            return;
        }

        $response = $this->arca->getVoucherInfo($voucher->number, $pointOfSale, $voucher->voucher_type);

        if ($response === null) {
            return;
        }

        $cae = $response['CodAutorizacion'] ?? $response['CAE'] ?? null;
        $documentNumber = $response['DocNro'] ?? null;
        $documentType = $response['DocTipo'] ?? null;
        $total = $response['ImpTotal'] ?? null;
        $request = $this->storage->lastAuthorizationRequest($voucher);
        $dateMatches = ! isset($request['CbteFch'], $response['CbteFch'])
            || (string) $request['CbteFch'] === str_replace('-', '', (string) $response['CbteFch']);
        $currencyMatches = ! isset($response['MonId'])
            || (string) $response['MonId'] === Currency::from($voucher->currency)->arcaCode();

        if ($cae === null || (string) $cae === '' || $documentNumber === null || $documentType === null || $total === null
            || (string) $documentNumber !== (string) $voucher->customer['document_number']
            || (int) $documentType !== $this->mapper->documentType((string) $voucher->customer['document_type'])
            || abs((float) $total - (float) $voucher->amounts['total']) > 0.01
            || ! $dateMatches || ! $currencyMatches) {
            throw new RuntimeException("ARCA voucher {$voucher->number} exists but does not match the local invoice. Manual reconciliation is required.");
        }

        $this->storage->updateVoucher($voucher, [
            'status' => VoucherStatus::Authorized->value,
            'cae' => (string) $cae,
            'cae_expires_at' => $this->normalizeCaeExpirationDate($response['FchVto'] ?? $response['CAEFchVto'] ?? null),
            'arca_response' => $response,
            'error_message' => null,
        ]);
    }

    private function normalizeCaeExpirationDate(mixed $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        $date = (string) $date;

        if (preg_match('/^\d{8}$/D', $date) !== 1) {
            return $date;
        }

        $parsedDate = DateTimeImmutable::createFromFormat('!Ymd', $date);
        $errors = DateTimeImmutable::getLastErrors();

        if ($parsedDate === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException('ARCA returned an invalid CAE expiration date.');
        }

        return $parsedDate->format('Y-m-d');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(IssueVoucherData $data, int $number, int $voucherType): array
    {
        $payload = [
            'CantReg' => 1,
            'PtoVta' => $data->pointOfSale,
            'CbteTipo' => $voucherType,
            'Concepto' => $this->mapper->concept($data->concept),
            'DocTipo' => $this->mapper->documentType((string) $data->customer['document_type']),
            'DocNro' => $data->customer['document_number'],
            'CbteDesde' => $number,
            'CbteHasta' => $number,
            'CbteFch' => (int) str_replace('-', '', $data->date),
            'ImpTotal' => $data->amounts['total'],
            'ImpTotConc' => $data->amounts['non_taxed'],
            'ImpNeto' => $data->amounts['taxable_net'],
            'ImpOpEx' => $data->amounts['exempt'],
            'ImpIVA' => $data->amounts['vat'],
            'ImpTrib' => $data->amounts['other_taxes'],
            'MonId' => $data->currency->arcaCode(),
            'MonCotiz' => $data->exchangeRate,
            'CondicionIVAReceptorId' => $this->mapper->vatCondition((string) $data->customer['vat_condition']),
        ];

        if ($data->concept->value !== 'products') {
            $payload['FchServDesde'] = (int) str_replace('-', '', $data->serviceFrom ?? $data->date);
            $payload['FchServHasta'] = (int) str_replace('-', '', $data->serviceTo ?? $data->date);
            $payload['FchVtoPago'] = (int) str_replace('-', '', $data->paymentDue ?? $data->date);
        }

        if ($data->vatRates !== []) {
            $payload['Iva'] = array_map(fn (array $vat): array => [
                'Id' => $this->mapper->vatRate((float) $vat['rate']),
                'BaseImp' => $vat['taxable_base'],
                'Importe' => $vat['amount'],
            ], $data->vatRates);
        }

        if ($data->associatedVoucher !== null) {
            $associatedType = $this->mapper->voucherType(
                VoucherLetter::from($data->associatedVoucher['letter']),
                VoucherOperation::Invoice,
            );

            $payload['CbtesAsoc'] = [[
                'Tipo' => $associatedType,
                'PtoVta' => $data->associatedVoucher['point_of_sale'],
                'Nro' => $data->associatedVoucher['number'],
            ]];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function responseMessage(array $response): string
    {
        $errors = $response['Errors']
            ?? $response['errors']
            ?? data_get($response, 'FeDetResp.FECAEDetResponse.Observaciones')
            ?? null;

        return $errors === null
            ? 'ARCA rejected the voucher without returning a message.'
            : (json_encode($errors) ?: 'ARCA rejected the voucher.');
    }
}
