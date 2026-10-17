<?php

namespace Modules\Arca\Models\Mongo;

use Modules\Arca\Models\Voucher as SqlVoucher;
use MongoDB\Laravel\Eloquent\DocumentModel;

class Voucher extends SqlVoucher
{
    use DocumentModel;

    protected $keyType = 'string';

    /** @return class-string<\Modules\Arca\Models\PointOfSale> */
    protected function pointModel(): string
    {
        return PointOfSale::class;
    }

    /** @return class-string<\Modules\Arca\Models\ArcaOperation> */
    protected function operationModel(): string
    {
        return ArcaOperation::class;
    }
}
