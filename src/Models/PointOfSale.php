<?php

namespace Modules\Arca\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $number
 * @property string $name
 * @property bool $active
 */
#[Fillable(['number', 'name', 'active'])]
class PointOfSale extends Model
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
        return $this->hasMany(Voucher::class);
    }

    /**
     * @return HasMany<Sequence, $this>
     */
    public function sequences(): HasMany
    {
        return $this->hasMany(Sequence::class);
    }
}
