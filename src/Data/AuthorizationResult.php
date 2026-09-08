<?php

namespace Modules\Arca\Data;

use Modules\Arca\Models\Voucher;

final readonly class AuthorizationResult
{
    public function __construct(public Voucher $voucher) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $pointOfSale = $this->voucher->pointOfSale?->number;

        return [
            'success' => $this->voucher->status === 'authorized',
            'status' => $this->voucher->status,
            'voucher_id' => $this->voucher->id,
            'point_of_sale' => $pointOfSale,
            'voucher_number' => $this->voucher->number,
            'formatted_number' => sprintf('%05d-%08d', $pointOfSale, $this->voucher->number),
            'currency' => $this->voucher->currency,
            'exchange_rate' => $this->voucher->exchange_rate,
            'cae' => $this->voucher->cae,
            'cae_expires_at' => $this->voucher->cae_expires_at?->toDateString(),
            'message' => $this->voucher->error_message,
        ];
    }
}
