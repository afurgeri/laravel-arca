<?php

namespace Modules\Arca\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed>|null $request
 */
#[Fillable([
    'voucher_id', 'correlation_id', 'method', 'status', 'http_status', 'attempt',
    'duration_ms', 'request', 'response', 'error_code', 'error_message',
])]
class ArcaOperation extends Model
{
    protected function casts(): array
    {
        return [
            'request' => 'array',
            'response' => 'array',
        ];
    }

    public function getTable(): string
    {
        return (string) config('arca.table_prefix', 'arca_').'operations';
    }

    /**
     * @return BelongsTo<Voucher, $this>
     */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}
