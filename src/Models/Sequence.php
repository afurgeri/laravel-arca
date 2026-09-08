<?php

namespace Modules\Arca\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $point_of_sale_id
 * @property int $voucher_type
 * @property int $next_number
 * @property int $last_synced_number
 */
#[Fillable(['point_of_sale_id', 'voucher_type', 'next_number', 'last_synced_number'])]
class Sequence extends Model
{
    public function getTable(): string
    {
        return (string) config('arca.table_prefix', 'arca_').'sequences';
    }

    /**
     * @return BelongsTo<PointOfSale, $this>
     */
    public function pointOfSale(): BelongsTo
    {
        return $this->belongsTo(PointOfSale::class);
    }
}
