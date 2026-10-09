<?php

namespace Modules\Arca\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $point_of_sale_id
 * @property int $voucher_type
 * @property string $letter
 * @property string $operation
 * @property string $currency
 * @property float $exchange_rate
 * @property int|null $number
 * @property int|null $attempted_number
 * @property string $status
 * @property string|null $cae
 * @property Carbon|null $cae_expires_at
 * @property string|null $error_message
 * @property array<string, mixed> $customer
 * @property array<string, mixed> $amounts
 */
#[Fillable([
    'cuit', 'environment', 'point_of_sale_id', 'voucher_type', 'letter', 'operation', 'currency', 'exchange_rate', 'number', 'attempted_number', 'status',
    'customer', 'amounts', 'vat_rates', 'associated_voucher', 'idempotency_key',
    'cae', 'cae_expires_at', 'arca_response', 'error_code', 'error_message',
])]
class Voucher extends Model
{
    protected function casts(): array
    {
        return [
            'customer' => 'array',
            'amounts' => 'array',
            'vat_rates' => 'array',
            'associated_voucher' => 'array',
            'arca_response' => 'array',
            'cae_expires_at' => 'date',
        ];
    }

    public function getTable(): string
    {
        return (string) config('arca.table_prefix', 'arca_').'vouchers';
    }

    /**
     * @return BelongsTo<PointOfSale, $this>
     */
    public function pointOfSale(): BelongsTo
    {
        return $this->belongsTo(PointOfSale::class);
    }

    /**
     * @return HasMany<ArcaOperation, $this>
     */
    public function operations(): HasMany
    {
        return $this->hasMany(ArcaOperation::class);
    }
}
