<?php

namespace App\Services\FisIntegration;

use App\Models\FisOutboundPackage;
use App\Services\FisIntegration\Dto\FisTransportResult;
use App\Services\FisIntegration\Exceptions\FisIntegrationException;

/**
 * @deprecated FIS support confirmed XML-over-HTTP, not SOAP. The legacy class is
 * retained only to fail safely if an old service binding references it directly.
 */
class SoapFisTransport implements FisTransportInterface
{
    public function send(FisOutboundPackage $package, string $xml): FisTransportResult
    {
        throw new FisIntegrationException('Deprecated SOAP transport is disabled. Official FIS protocol is XML-over-HTTP; use XmlHttpFisTransport or CollegePortal Gateway.');
    }

    public function status(FisOutboundPackage $package): FisTransportResult
    {
        throw new FisIntegrationException('Deprecated SOAP status transport is disabled. Official FIS status request must be implemented as XML-over-HTTP after XSD/spec confirmation.');
    }
}
