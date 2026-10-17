<?php

namespace Modules\Arca\Models\Mongo;

use Modules\Arca\Models\PointOfSale as SqlPointOfSale;
use MongoDB\Laravel\Eloquent\DocumentModel;

class PointOfSale extends SqlPointOfSale
{
    use DocumentModel;

    protected $keyType = 'string';

    /** @return class-string<\Modules\Arca\Models\Voucher> */
    protected function voucherModel(): string
    {
        return Voucher::class;
    }

    /** @return class-string<\Modules\Arca\Models\Sequence> */
    protected function sequenceModel(): string
    {
        return Sequence::class;
    }
}
