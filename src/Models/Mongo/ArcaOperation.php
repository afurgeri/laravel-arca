<?php

namespace Modules\Arca\Models\Mongo;

use Modules\Arca\Models\ArcaOperation as SqlArcaOperation;
use MongoDB\Laravel\Eloquent\DocumentModel;

class ArcaOperation extends SqlArcaOperation
{
    use DocumentModel;

    protected $keyType = 'string';

    /** @return class-string<\Modules\Arca\Models\Voucher> */
    protected function voucherModel(): string
    {
        return Voucher::class;
    }
}
