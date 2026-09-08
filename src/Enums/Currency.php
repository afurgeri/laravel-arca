<?php

namespace Modules\Arca\Enums;

enum Currency: string
{
    case Ars = 'ARS';
    case Usd = 'USD';

    public function arcaCode(): string
    {
        return match ($this) {
            self::Ars => 'PES',
            self::Usd => 'DOL',
        };
    }
}
