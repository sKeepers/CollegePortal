<?php

namespace App\Services\FisIntegration;

use App\Models\FisOutboundPackage;
use App\Services\FisIntegration\Exceptions\FisIntegrationException;

class FisPackageStatusService
{
    public function refresh(FisOutboundPackage $package, bool $mock = false): array
    {
        if (! $package->package_id) {
            throw new FisIntegrationException('PackageID is missing. Send package first.');
        }
        $transport = $mock ? new MockFisTransport() : new SoapFisTransport();
        $result = $transport->status($package);
        $status = $result->status ?: $package->status;
        $package->update(['status' => $status, 'completed_at' => $status === 'completed' ? now() : $package->completed_at, 'failed_at' => in_array($status, ['failed','rejected'], true) ? now() : $package->failed_at, 'last_error_code' => $result->errorCode, 'last_error_message' => $result->errorMessage, 'response_metadata' => $result->metadata]);
        $package->events()->create(['event_type' => 'status_refresh', 'status' => $package->status, 'metadata' => ['status' => $status, 'error_code' => $result->errorCode], 'user_id' => auth()->id()]);
        return ['ok' => $result->ok, 'status' => $status, 'error_code' => $result->errorCode, 'error_message' => $result->errorMessage];
    }
}
