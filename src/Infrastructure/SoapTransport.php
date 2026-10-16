<?php

namespace Modules\Arca\Infrastructure;

interface SoapTransport
{
    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function call(string $endpoint, string $namespace, string $operation, array $arguments): array;
}
