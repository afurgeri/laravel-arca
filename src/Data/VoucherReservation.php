<?php

namespace Modules\Arca\Data;

use Modules\Arca\Models\Voucher;

final readonly class VoucherReservation
{
    public function __construct(public Voucher $voucher, public bool $created) {}
}
