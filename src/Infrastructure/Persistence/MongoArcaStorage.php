<?php

namespace Modules\Arca\Infrastructure\Persistence;

use Closure;
use Modules\Arca\Models\ArcaOperation;
use Modules\Arca\Models\PointOfSale;
use Modules\Arca\Models\Sequence;
use Modules\Arca\Models\Voucher;
use MongoDB\Laravel\Connection;
use RuntimeException;
use Throwable;

class MongoArcaStorage extends EloquentArcaStorage
{
    protected function isDuplicateKey(Throwable $exception): bool
    {
        return $exception instanceof \MongoDB\Driver\Exception\RuntimeException && $exception->getCode() === 11000;
    }

    protected function pointModel(): PointOfSale
    {
        return new \Modules\Arca\Models\Mongo\PointOfSale;
    }

    protected function voucherModel(): Voucher
    {
        return new \Modules\Arca\Models\Mongo\Voucher;
    }

    protected function sequenceModel(): Sequence
    {
        return new \Modules\Arca\Models\Mongo\Sequence;
    }

    protected function operationModel(): ArcaOperation
    {
        return new \Modules\Arca\Models\Mongo\ArcaOperation;
    }

    protected function lockSequence(PointOfSale $point, int $type): Sequence
    {
        $sequence = $this->sequenceModel()->newQuery()->firstOrCreate(
            ['point_of_sale_id' => $point->id, 'voucher_type' => $type],
            ['next_number' => 1, 'last_synced_number' => 0],
        );

        // Every reservation writes the same document: concurrent transactions conflict rather than read a shared snapshot.
        $sequence->increment('reservation_version');

        return $sequence->refresh();
    }

    protected function lockedOccupiedVoucher(PointOfSale $point, int $type, int $number): ?Voucher
    {
        return $this->occupiedVoucher($point, $type, $number);
    }

    protected function transaction(Closure $callback): mixed
    {
        $connection = $this->pointModel()->getConnection();

        if (! $connection instanceof Connection) {
            throw new RuntimeException('ARCA MongoDB storage requires a MongoDB connection.');
        }

        $hello = $connection->getDatabase()->command(['hello' => 1])->toArray()[0];

        if (! isset($hello['setName']) && ($hello['msg'] ?? null) !== 'isdbgrid') {
            throw new RuntimeException('ARCA MongoDB storage requires a replica set or sharded cluster with transaction support.');
        }

        return $connection->transaction($callback, 3);
    }
}
