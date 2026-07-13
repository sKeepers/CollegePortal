<?php

namespace App\Services\FisIntegration;

use App\Models\FisOutboundPackage;
use App\Services\FisIntegration\Dto\FisTransportResult;

interface FisTransportInterface
{
    public function send(FisOutboundPackage $package, string $xml): FisTransportResult;
    public function status(FisOutboundPackage $package): FisTransportResult;
}
