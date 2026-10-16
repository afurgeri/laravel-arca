<?php

namespace Modules\Arca\Contracts;

use Modules\Arca\Data\ArcaCredentials;

interface ArcaAuthorizer
{
    /** @return array{Token: string, Sign: string} */
    public function authorization(ArcaCredentials $credentials): array;
}
