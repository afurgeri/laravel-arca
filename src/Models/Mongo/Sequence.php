<?php

namespace Modules\Arca\Models\Mongo;

use Modules\Arca\Models\Sequence as SqlSequence;
use MongoDB\Laravel\Eloquent\DocumentModel;

class Sequence extends SqlSequence
{
    use DocumentModel;

    protected $keyType = 'string';

    /** @return class-string<\Modules\Arca\Models\PointOfSale> */
    protected function pointModel(): string
    {
        return PointOfSale::class;
    }
}
