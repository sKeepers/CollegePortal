<?php

namespace App\Services\FisIntegration;

use App\Models\FisOutboundPackage;
use App\Services\FisIntegration\Dto\FisTransportResult;
use Illuminate\Support\Str;

class MockFisTransport implements FisTransportInterface
{
    public function send(FisOutboundPackage $package, string $xml): FisTransportResult
    {
        if (str_contains($xml, '<MockRejected>')) {
            return new FisTransportResult(false, null, 'rejected', 'MOCK_REJECTED', 'Mock transport rejected package.', ['transport' => 'mock']);
        }

        return new FisTransportResult(true, $package->package_id ?: 'MOCK-'.Str::upper(Str::random(12)), 'accepted', null, null, ['transport' => 'mock']);
    }

    public function status(FisOutboundPackage $package): FisTransportResult
    {
        return new FisTransportResult(true, $package->package_id, $package->status === 'accepted' ? 'completed' : ($package->status ?: 'processing'), null, null, ['transport' => 'mock']);
    }
}
