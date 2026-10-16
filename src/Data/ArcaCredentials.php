<?php

namespace Modules\Arca\Data;

use InvalidArgumentException;

final readonly class ArcaCredentials
{
    public function __construct(
        public string $cuit,
        public ?string $accessToken = null,
        public bool $production = false,
        public ?string $certificate = null,
        public ?string $privateKey = null,
    ) {
        if (preg_match('/^\d{11}$/D', $this->cuit) !== 1) {
            throw new InvalidArgumentException('The ARCA CUIT must contain exactly 11 digits.');
        }

        if (($this->certificate === null) !== ($this->privateKey === null)) {
            throw new InvalidArgumentException('The ARCA certificate and private key must be provided together.');
        }
    }

    public function environment(): string
    {
        return $this->production ? 'production' : 'testing';
    }
}
