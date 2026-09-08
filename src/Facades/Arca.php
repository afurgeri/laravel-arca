<?php

namespace Modules\Arca\Facades;

use Illuminate\Support\Facades\Facade;
use Modules\Arca\ArcaManager;

class Arca extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ArcaManager::class;
    }
}
