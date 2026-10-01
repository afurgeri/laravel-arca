<?php

namespace Modules\Arca;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Arca\Contracts\ArcaClient;
use Modules\Arca\Models\ArcaOperation;
use Modules\Arca\Models\PointOfSale;
use Modules\Arca\Models\Sequence;
use Throwable;

class PointOfSaleManager
{
    public function __construct(
        private readonly ArcaClient $arca,
        private readonly ArcaCodeMapper $mapper,
    ) {}

    /**
     * @return Collection<int, PointOfSale>
     */
    public function active(): Collection
    {
        return PointOfSale::query()->where('active', true)->orderBy('number')->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): PointOfSale
    {
        return PointOfSale::query()->create($attributes);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fromArca(bool $recordOperations = true): array
    {
        $correlationId = (string) Str::uuid();
        $startedAt = microtime(true);

        try {
            $salesPoints = $this->arca->getSalesPoints();
            $result = array_map(fn (array $salesPoint): array => $this->salesPointWithCounters($salesPoint, $recordOperations), $salesPoints);

            if ($recordOperations) {
                ArcaOperation::query()->create([
                    'correlation_id' => $correlationId,
                    'method' => 'ElectronicBilling.GetSalesPoints',
                    'status' => 'successful',
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'response' => $salesPoints,
                ]);
            }

            return $result;
        } catch (Throwable $exception) {
            if ($recordOperations) {
                ArcaOperation::query()->create([
                    'correlation_id' => $correlationId,
                    'method' => 'ElectronicBilling.GetSalesPoints',
                    'status' => 'failed',
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'error_message' => $exception->getMessage(),
                ]);
            }

            throw $exception;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function syncFromArca(): array
    {
        $salesPoints = $this->fromArca();
        $voucherTypes = $this->mapper->voucherTypes();

        foreach ($salesPoints as $salesPoint) {
            $pointOfSale = PointOfSale::query()->updateOrCreate(
                ['number' => $salesPoint['number']],
                [
                    'name' => $salesPoint['name'],
                    'active' => $salesPoint['active'],
                ],
            );

            foreach ($salesPoint['counters'] as $index => $counter) {
                $voucherType = $voucherTypes[$index]['type'];
                $lastNumber = (int) $counter['last_number'];

                DB::transaction(function () use ($pointOfSale, $voucherType, $lastNumber): void {
                    $sequence = Sequence::query()->firstOrCreate(
                        ['point_of_sale_id' => $pointOfSale->id, 'voucher_type' => $voucherType],
                        ['next_number' => 1, 'last_synced_number' => 0],
                    );
                    $sequence = Sequence::query()->whereKey($sequence->id)->lockForUpdate()->firstOrFail();

                    $sequence->update([
                        'next_number' => $lastNumber + 1,
                        'last_synced_number' => $lastNumber,
                    ]);
                });
            }
        }

        return $salesPoints;
    }

    /**
     * @param  array<string, mixed>  $salesPoint
     * @return array<string, mixed>
     */
    private function salesPointWithCounters(array $salesPoint, bool $recordOperations): array
    {
        $number = (int) ($salesPoint['Nro'] ?? $salesPoint['number'] ?? 0);

        return [
            'number' => $number,
            'name' => $salesPoint['Desc'] ?? $salesPoint['Descripcion'] ?? "Punto de venta {$number}",
            'active' => ! isset($salesPoint['FchBaja']) && ! ($salesPoint['Bloqueado'] ?? false),
            'counters' => array_map(function (array $voucherType) use ($number, $recordOperations): array {
                $lastNumber = $this->lastVoucher($number, $voucherType['type'], $recordOperations);

                return [
                    'letter' => $voucherType['letter'],
                    'operation' => $voucherType['operation'],
                    'label' => $voucherType['label'],
                    'last_number' => $lastNumber,
                    'next_number' => $lastNumber + 1,
                ];
            }, $this->mapper->voucherTypes()),
        ];
    }

    private function lastVoucher(int $pointOfSale, int $voucherType, bool $recordOperations): int
    {
        $startedAt = microtime(true);
        $correlationId = (string) Str::uuid();

        try {
            $last = $this->arca->getLastVoucher($pointOfSale, $voucherType);
            if ($recordOperations) {
                ArcaOperation::query()->create([
                    'correlation_id' => $correlationId,
                    'method' => 'ElectronicBilling.GetLastVoucher',
                    'status' => 'successful',
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'request' => ['point_of_sale' => $pointOfSale, 'voucher_type' => $voucherType],
                    'response' => ['last_voucher' => $last],
                ]);
            }

            return $last;
        } catch (Throwable $exception) {
            if ($recordOperations) {
                ArcaOperation::query()->create([
                    'correlation_id' => $correlationId,
                    'method' => 'ElectronicBilling.GetLastVoucher',
                    'status' => 'failed',
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'request' => ['point_of_sale' => $pointOfSale, 'voucher_type' => $voucherType],
                    'error_message' => $exception->getMessage(),
                ]);
            }

            throw $exception;
        }
    }
}
