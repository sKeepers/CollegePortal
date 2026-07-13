<?php

namespace App\Services\FisIntegration\Dto;

class FisTransportResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $packageId = null,
        public readonly ?string $status = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly array $metadata = [],
    ) {}
}
