<?php

namespace Modules\Arca\Contracts;

interface ArcaClient
{
    /**
     * @return list<array<string, mixed>>
     */
    public function getSalesPoints(): array;

    public function getLastVoucher(int $pointOfSale, int $voucherType): int;

    /**
     * @return array<string, mixed>|null
     */
    public function getVoucherInfo(int $number, int $pointOfSale, int $voucherType): ?array;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createVoucher(array $data): array;
}
