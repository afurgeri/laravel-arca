<?php

namespace Modules\Arca\Enums;

enum Currency: string
{
    case Ars = 'ARS';
    case Usd = 'USD';
    case Eur = 'EUR';

    public function arcaCode(): string
    {
        return match ($this) {
            self::Ars => 'PES',
            self::Usd => 'DOL',
            self::Eur => '060',
        };
    }
}
