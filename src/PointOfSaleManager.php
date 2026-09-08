<?php

namespace Modules\Arca;

use Illuminate\Database\Eloquent\Collection;
use Modules\Arca\Models\PointOfSale;

class PointOfSaleManager
{
    /**
     * @return Collection<int, PointOfSale>
     */
    public function active(): Collection
    {
        return PointOfSale::query()->where('active', true)->orderBy('number')->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): PointOfSale
    {
        return PointOfSale::query()->create($attributes);
    }
}
