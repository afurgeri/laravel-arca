<?php

namespace Modules\Arca;

final readonly class ArcaScope
{
    public function __construct(public ?string $cuit, public ?string $environment) {}

    /** @return array{cuit: string|null, environment: string|null} */
    public function attributes(): array
    {
        return ['cuit' => $this->cuit, 'environment' => $this->environment];
    }
}
