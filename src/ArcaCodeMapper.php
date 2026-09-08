<?php

namespace Modules\Arca;

use Modules\Arca\Enums\VoucherConcept;
use Modules\Arca\Enums\VoucherLetter;
use Modules\Arca\Enums\VoucherOperation;
use Modules\Arca\Exceptions\ArcaException;

class ArcaCodeMapper
{
    public function voucherType(VoucherLetter $letter, VoucherOperation $operation): int
    {
        return match ([$letter, $operation]) {
            [VoucherLetter::A, VoucherOperation::Invoice] => 1,
            [VoucherLetter::A, VoucherOperation::DebitNote] => 2,
            [VoucherLetter::A, VoucherOperation::CreditNote] => 3,
            [VoucherLetter::B, VoucherOperation::Invoice] => 6,
            [VoucherLetter::B, VoucherOperation::DebitNote] => 7,
            [VoucherLetter::B, VoucherOperation::CreditNote] => 8,
            [VoucherLetter::C, VoucherOperation::Invoice] => 11,
            [VoucherLetter::C, VoucherOperation::DebitNote] => 12,
            [VoucherLetter::C, VoucherOperation::CreditNote] => 13,
        };
    }

    public function documentType(string $type): int
    {
        return match ($type) {
            'cuit' => 80,
            'cuil' => 86,
            'dni' => 96,
            'consumer_final' => 99,
            default => throw new ArcaException("Unsupported document type [{$type}]."),
        };
    }

    public function vatCondition(string $condition): int
    {
        return match ($condition) {
            'responsible_registered' => 1,
            'exempt' => 4,
            'consumer_final' => 5,
            'monotributo' => 6,
            'not_categorized' => 7,
            'foreign_customer' => 9,
            'not_reached' => 15,
            default => throw new ArcaException("Unsupported VAT condition [{$condition}]."),
        };
    }

    public function concept(VoucherConcept $concept): int
    {
        return $concept->afipCode();
    }

    public function vatRate(float $rate): int
    {
        return match ($rate) {
            0.0 => 3,
            2.5 => 9,
            5.0 => 8,
            10.5 => 4,
            21.0 => 5,
            27.0 => 6,
            default => throw new ArcaException("Unsupported VAT rate [{$rate}]."),
        };
    }
}
