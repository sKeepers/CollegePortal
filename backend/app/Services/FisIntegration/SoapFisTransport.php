<?php

namespace App\Services\FisIntegration;

use App\Models\FisOutboundPackage;
use App\Services\FisIntegration\Dto\FisTransportResult;
use App\Services\FisIntegration\Exceptions\FisIntegrationException;

class SoapFisTransport implements FisTransportInterface
{
    public function send(FisOutboundPackage $package, string $xml): FisTransportResult
    {
        if ($package->environment === 'production' && ! config('fis_api.allow_production_send')) {
            throw new FisIntegrationException('Production FIS send is blocked by feature flag.');
        }

        throw new FisIntegrationException('Official SOAP methods are not configured. Load WSDL/spec first and implement documented envelope mapping.');
    }

    public function status(FisOutboundPackage $package): FisTransportResult
    {
        throw new FisIntegrationException('Official SOAP status method is not configured. Load WSDL/spec first.');
    }
}
