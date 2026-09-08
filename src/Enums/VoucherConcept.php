<?php

namespace Modules\Arca\Enums;

enum VoucherConcept: string
{
    case Products = 'products';
    case Services = 'services';
    case ProductsAndServices = 'products_and_services';

    public function afipCode(): int
    {
        return match ($this) {
            self::Products => 1,
            self::Services => 2,
            self::ProductsAndServices => 3,
        };
    }
}
