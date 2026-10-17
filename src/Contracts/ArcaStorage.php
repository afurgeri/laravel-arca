<?php

namespace Modules\Arca\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Modules\Arca\ArcaScope;
use Modules\Arca\Data\IssueVoucherData;
use Modules\Arca\Data\VoucherReservation;
use Modules\Arca\Models\ArcaOperation;
use Modules\Arca\Models\PointOfSale;
use Modules\Arca\Models\Voucher;

interface ArcaStorage
{
    /** @return Collection<int, PointOfSale> */
    public function activePoints(ArcaScope $scope): Collection;

    /** @param array<string, mixed> $attributes */
    public function createPoint(ArcaScope $scope, array $attributes): PointOfSale;

    public function activePoint(ArcaScope $scope, int $number): PointOfSale;

    /** @param array<string, mixed> $attributes */
    public function syncPoint(ArcaScope $scope, int $number, array $attributes): PointOfSale;

    public function syncSequence(PointOfSale $point, int $type, int $lastNumber): void;

    public function findIdempotentVoucher(ArcaScope $scope, string $key): ?Voucher;

    public function occupiedVoucher(PointOfSale $point, int $type, int $number): ?Voucher;

    public function reserveVoucher(ArcaScope $scope, IssueVoucherData $data, int $type, int $lastNumber, ?Voucher $reconciled): VoucherReservation;

    /** @param array<string, mixed> $attributes */
    public function updateVoucher(Voucher $voucher, array $attributes): void;

    /** @param array<string, mixed> $attributes */
    public function recordOperation(ArcaScope $scope, array $attributes): ArcaOperation;

    /** @return array<string, mixed>|null */
    public function lastAuthorizationRequest(Voucher $voucher): ?array;

    /** @return array<string, array{unassigned: int, conflicting: int}> */
    public function legacySummary(ArcaScope $scope): array;

    public function assignLegacy(ArcaScope $scope): void;
}
