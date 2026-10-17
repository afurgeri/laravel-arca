<?php

namespace Modules\Arca\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int|string $id
 * @property int $number
 * @property string $name
 * @property bool $active
 */
#[Fillable(['cuit', 'environment', 'number', 'name', 'active'])]
class PointOfSale extends ArcaModel
{
    public function getTable(): string
    {
        return (string) config('arca.table_prefix', 'arca_').'points_of_sale';
    }

    /**
     * @return HasMany<Voucher, $this>
     */
    public function vouchers(): HasMany
    {
        return $this->hasMany($this->voucherModel(), 'point_of_sale_id');
    }

    /**
     * @return HasMany<Sequence, $this>
     */
    public function sequences(): HasMany
    {
        return $this->hasMany($this->sequenceModel(), 'point_of_sale_id');
    }

    /** @return class-string<Voucher> */
    protected function voucherModel(): string
    {
        return Voucher::class;
    }

    /** @return class-string<Sequence> */
    protected function sequenceModel(): string
    {
        return Sequence::class;
    }
}
