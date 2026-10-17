<?php

namespace Modules\Arca\Infrastructure\Persistence;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Arca\ArcaScope;
use Modules\Arca\Contracts\ArcaStorage;
use Modules\Arca\Data\IssueVoucherData;
use Modules\Arca\Data\VoucherReservation;
use Modules\Arca\Enums\VoucherStatus;
use Modules\Arca\Models\ArcaOperation;
use Modules\Arca\Models\PointOfSale;
use Modules\Arca\Models\Sequence;
use Modules\Arca\Models\Voucher;
use RuntimeException;
use Throwable;

class EloquentArcaStorage implements ArcaStorage
{
    protected function pointModel(): PointOfSale
    {
        return new PointOfSale;
    }

    protected function voucherModel(): Voucher
    {
        return new Voucher;
    }

    protected function sequenceModel(): Sequence
    {
        return new Sequence;
    }

    protected function operationModel(): ArcaOperation
    {
        return new ArcaOperation;
    }

    public function activePoints(ArcaScope $scope): Collection
    {
        return $this->pointModel()->newQuery()->where($scope->attributes())->where('active', true)->orderBy('number')->get();
    }

    public function createPoint(ArcaScope $scope, array $attributes): PointOfSale
    {
        return $this->pointModel()->newQuery()->create($scope->attributes() + $attributes);
    }

    public function activePoint(ArcaScope $scope, int $number): PointOfSale
    {
        return $this->pointModel()->newQuery()->where($scope->attributes())->where('number', $number)->where('active', true)->firstOrFail();
    }

    public function syncPoint(ArcaScope $scope, int $number, array $attributes): PointOfSale
    {
        return $this->pointModel()->newQuery()->updateOrCreate($scope->attributes() + ['number' => $number], $attributes);
    }

    public function syncSequence(PointOfSale $point, int $type, int $lastNumber): void
    {
        $this->transaction(function () use ($point, $type, $lastNumber): void {
            $sequence = $this->lockSequence($point, $type);
            $sequence->update(['next_number' => $lastNumber + 1, 'last_synced_number' => $lastNumber]);
        });
    }

    public function findIdempotentVoucher(ArcaScope $scope, string $key): ?Voucher
    {
        return $this->voucherModel()->newQuery()->where($scope->attributes())->where('idempotency_key', $key)->first();
    }

    public function occupiedVoucher(PointOfSale $point, int $type, int $number): ?Voucher
    {
        return $this->voucherModel()->newQuery()->where('point_of_sale_id', $point->id)->where('voucher_type', $type)->where('number', $number)->first();
    }

    public function reserveVoucher(ArcaScope $scope, IssueVoucherData $data, int $type, int $lastNumber, ?Voucher $reconciled): VoucherReservation
    {
        try {
            return $this->transaction(function () use ($scope, $data, $type, $lastNumber, $reconciled): VoucherReservation {
                $point = $this->activePoint($scope, $data->pointOfSale);
                $sequence = $this->lockSequence($point, $type);

                if ($data->idempotencyKey !== null) {
                    $existing = $this->findIdempotentVoucher($scope, $data->idempotencyKey);

                    if ($existing !== null) {
                        return new VoucherReservation($existing, false);
                    }
                }

                $number = $lastNumber + 1;
                $occupied = $this->lockedOccupiedVoucher($point, $type, $number);

                if ($occupied !== null) {
                    if ($reconciled === null || (string) $occupied->id !== (string) $reconciled->id
                        || $occupied->status !== $reconciled->status
                        || ! in_array($occupied->status, [VoucherStatus::Rejected->value, VoucherStatus::Unknown->value], true)) {
                        throw new RuntimeException("ARCA voucher {$number} is already reserved locally. Verify its authorization before issuing another voucher.");
                    }

                    $occupied->update(['attempted_number' => $number, 'number' => null, 'status' => VoucherStatus::Failed->value]);
                }

                $sequence->update([
                    'next_number' => $number + 1,
                    'last_synced_number' => max((int) $sequence->last_synced_number, $lastNumber),
                ]);
                $voucher = $this->voucherModel()->newQuery()->create($scope->attributes() + [
                    'point_of_sale_id' => $point->id,
                    'voucher_type' => $type,
                    'letter' => $data->letter->value,
                    'operation' => $data->operation->value,
                    'currency' => $data->currency->value,
                    'exchange_rate' => $data->exchangeRate,
                    'number' => $number,
                    'status' => VoucherStatus::Pending->value,
                    'customer' => $data->customer,
                    'amounts' => $data->amounts,
                    'vat_rates' => $data->vatRates,
                    'associated_voucher' => $data->associatedVoucher,
                    'idempotency_key' => $data->idempotencyKey,
                ]);

                return new VoucherReservation($voucher, true);
            });
        } catch (Throwable $exception) {
            if (! $this->isDuplicateKey($exception)) {
                throw $exception;
            }

            $existing = $data->idempotencyKey === null ? null : $this->findIdempotentVoucher($scope, $data->idempotencyKey);

            if ($existing !== null) {
                return new VoucherReservation($existing, false);
            }

            throw $exception;
        }
    }

    protected function isDuplicateKey(Throwable $exception): bool
    {
        return $exception instanceof UniqueConstraintViolationException;
    }

    public function updateVoucher(Voucher $voucher, array $attributes): void
    {
        $voucher->update($attributes);
    }

    public function recordOperation(ArcaScope $scope, array $attributes): ArcaOperation
    {
        return $this->operationModel()->newQuery()->create($scope->attributes() + $attributes);
    }

    public function lastAuthorizationRequest(Voucher $voucher): ?array
    {
        return $this->operationModel()->newQuery()->where('voucher_id', $voucher->id)
            ->where('method', 'ElectronicBilling.CreateVoucher')->latest('created_at')->latest($this->operationModel()->getKeyName())->first()?->request;
    }

    public function legacySummary(ArcaScope $scope): array
    {
        $summary = [];

        foreach ([$this->pointModel(), $this->voucherModel(), $this->operationModel()] as $model) {
            $query = $model->newQuery();
            $conflicting = (clone $query)->where(function ($query) use ($scope): void {
                $query->where(function ($query): void {
                    $query->whereNull('cuit')->whereNotNull('environment')->orWhereNotNull('cuit')->whereNull('environment');
                })->orWhere(function ($query) use ($scope): void {
                    $query->whereNotNull('cuit')->whereNotNull('environment')->where(function ($query) use ($scope): void {
                        $query->where('cuit', '!=', $scope->cuit)->orWhere('environment', '!=', $scope->environment);
                    });
                });
            })->count();
            $summary[class_basename($model)] = [
                'unassigned' => (clone $query)->whereNull('cuit')->whereNull('environment')->count(),
                'conflicting' => $conflicting,
            ];
        }

        return $summary;
    }

    public function assignLegacy(ArcaScope $scope): void
    {
        $this->transaction(function () use ($scope): void {
            foreach ($this->legacySummary($scope) as $counts) {
                if ($counts['conflicting'] > 0) {
                    throw new RuntimeException('Conflicting ARCA records found; no changes made.');
                }
            }

            foreach ([$this->pointModel(), $this->voucherModel(), $this->operationModel()] as $model) {
                $model->newQuery()->whereNull('cuit')->whereNull('environment')->update($scope->attributes());
            }
        });
    }

    protected function lockSequence(PointOfSale $point, int $type): Sequence
    {
        $sequence = $this->sequenceModel()->newQuery()->firstOrCreate(
            ['point_of_sale_id' => $point->id, 'voucher_type' => $type],
            ['next_number' => 1, 'last_synced_number' => 0],
        );

        return $this->sequenceModel()->newQuery()->whereKey($sequence->getKey())->lockForUpdate()->firstOrFail();
    }

    protected function lockedOccupiedVoucher(PointOfSale $point, int $type, int $number): ?Voucher
    {
        return $this->voucherModel()->newQuery()->where('point_of_sale_id', $point->id)
            ->where('voucher_type', $type)->where('number', $number)->lockForUpdate()->first();
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function transaction(Closure $callback): mixed
    {
        return $this->pointModel()->getConnection()->transaction($callback);
    }
}
