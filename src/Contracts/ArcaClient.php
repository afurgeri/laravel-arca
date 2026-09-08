<?php

namespace Modules\Arca\Contracts;

interface ArcaClient
{
    public function getLastVoucher(int $pointOfSale, int $voucherType): int;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createVoucher(array $data): array;
}
