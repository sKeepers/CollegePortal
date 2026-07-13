<?php

namespace App\Services\FisIntegration;

use App\Models\FisOutboundPackage;
use App\Services\AuditLogService;
use App\Services\FisIntegration\Exceptions\FisIntegrationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FisPackageSender
{
    public function send(FisOutboundPackage $package, bool $preview = false, bool $mock = false): array
    {
        if ($package->environment === 'production' && ! config('fis_api.allow_production_send')) {
            $this->event($package, 'production_attempt_blocked', ['environment' => $package->environment]);
            throw new FisIntegrationException('Production FIS send is blocked by feature flag.');
        }
        if (! $package->payload_path || ! Storage::disk('local')->exists($package->payload_path)) {
            throw new FisIntegrationException('XML payload is missing.');
        }
        if (! in_array($package->status, ['validated','sent','accepted','processing','failed'], true) && ! $preview) {
            throw new FisIntegrationException('Package must be validated before send.');
        }

        $xml = Storage::disk('local')->get($package->payload_path);
        $requestId = $package->request_id ?: (string) Str::uuid();
        if ($preview) {
            $this->event($package, 'send_preview', ['request_id' => $requestId, 'payload_sha256' => hash('sha256', $xml)]);
            AuditLogService::log('fis_outbound', 'send_preview', $package, null, ['request_id' => $requestId, 'payload_sha256' => hash('sha256', $xml)]);
            return ['ok' => true, 'request_id' => $requestId, 'payload_sha256' => hash('sha256', $xml), 'redacted_preview' => $this->redact($xml)];
        }

        $transport = $mock ? new MockFisTransport() : $this->transport();
        $package->update(['status' => 'sending', 'request_id' => $requestId]);
        $result = $transport->send($package, $xml);
        $package->update(['status' => $result->ok ? 'accepted' : 'failed', 'package_id' => $result->packageId, 'sent_at' => now(), 'accepted_at' => $result->ok ? now() : null, 'failed_at' => $result->ok ? null : now(), 'last_error_code' => $result->errorCode, 'last_error_message' => $result->errorMessage, 'response_metadata' => $result->metadata]);
        $this->event($package, $result->ok ? 'sent_test' : 'send_failed', ['request_id' => $requestId, 'package_id' => $result->packageId, 'status' => $result->status, 'error_code' => $result->errorCode]);
        AuditLogService::log('fis_outbound', $result->ok ? 'send_test' : 'send_failed', $package, null, ['request_id' => $requestId, 'package_id' => $result->packageId, 'status' => $result->status, 'error_code' => $result->errorCode]);
        return ['ok' => $result->ok, 'package_id' => $result->packageId, 'status' => $result->status, 'error_code' => $result->errorCode, 'error_message' => $result->errorMessage];
    }

    private function transport(): FisTransportInterface
    {
        return config('fis_api.transport') === 'gateway' ? new GatewayFisTransport() : new SoapFisTransport();
    }

    private function event(FisOutboundPackage $package, string $type, array $metadata = []): void
    {
        $package->events()->create(['event_type' => $type, 'status' => $package->status, 'request_id' => $package->request_id, 'metadata' => $metadata, 'user_id' => auth()->id()]);
    }

    private function redact(string $xml): string
    {
        return preg_replace('/>([^<]{4})[^<]+([^<]{2})</u', '>$1***$2<', $xml) ?: '[redacted]';
    }
}
