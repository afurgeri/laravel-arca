<?php

namespace Modules\Arca;

use Modules\Arca\Data\AuthorizationResult;
use Modules\Arca\Data\IssueVoucherData;

class ArcaManager
{
    public function __construct(
        private readonly PointOfSaleManager $pointOfSales,
        private readonly VoucherAuthorizationManager $vouchers,
    ) {}

    public function pointOfSales(): PointOfSaleManager
    {
        return $this->pointOfSales;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function authorize(array $data): AuthorizationResult
    {
        return $this->vouchers->authorize(IssueVoucherData::fromArray($data));
    }
}
