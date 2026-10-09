<?php

namespace Modules\Arca;

use Modules\Arca\Contracts\ArcaClient;
use Modules\Arca\Data\AuthorizationResult;
use Modules\Arca\Data\IssueVoucherData;
use Modules\Arca\Exceptions\ArcaException;

class ArcaManager
{
    public function __construct(
        private readonly PointOfSaleManager $pointOfSales,
        private readonly VoucherAuthorizationManager $vouchers,
        private readonly ?ArcaClient $client = null,
    ) {}

    public function pointOfSales(): PointOfSaleManager
    {
        return $this->pointOfSales;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getVoucherTypes(): array
    {
        if ($this->client === null) {
            throw new ArcaException('The ARCA client is unavailable for voucher type lookup.');
        }

        return $this->client->getVoucherTypes();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function authorize(array $data): AuthorizationResult
    {
        return $this->vouchers->authorize(IssueVoucherData::fromArray($data));
    }
}
