<?php

namespace Modules\Arca\Infrastructure;

use Closure;
use Modules\Arca\Contracts\ArcaClient;

class LazyArcaClient implements ArcaClient
{
    private ?ArcaClient $client = null;

    /** @param Closure(): ArcaClient $resolver */
    public function __construct(private readonly Closure $resolver) {}

    public function getSalesPoints(): array
    {
        return $this->client()->getSalesPoints();
    }

    public function getVoucherTypes(): array
    {
        return $this->client()->getVoucherTypes();
    }

    public function getLastVoucher(int $pointOfSale, int $voucherType): int
    {
        return $this->client()->getLastVoucher($pointOfSale, $voucherType);
    }

    public function getVoucherInfo(int $number, int $pointOfSale, int $voucherType): ?array
    {
        return $this->client()->getVoucherInfo($number, $pointOfSale, $voucherType);
    }

    public function createVoucher(array $data): array
    {
        return $this->client()->createVoucher($data);
    }

    private function client(): ArcaClient
    {
        return $this->client ??= ($this->resolver)();
    }
}
