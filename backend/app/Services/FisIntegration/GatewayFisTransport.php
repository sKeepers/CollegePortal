<?php

namespace App\Services\FisIntegration;

use App\Models\FisOutboundPackage;
use App\Services\FisIntegration\Dto\FisTransportResult;
use App\Services\FisIntegration\Exceptions\FisIntegrationException;
use Illuminate\Support\Facades\Http;

class GatewayFisTransport implements FisTransportInterface
{
    public function send(FisOutboundPackage $package, string $xml): FisTransportResult
    {
        $response = $this->client()->post('/fis/test/send', [
            'request_id' => $package->request_id,
            'package_hash' => hash('sha256', $xml),
            'package_type' => $package->package_type,
            'schema_version' => $package->schema_version,
            'payload' => base64_encode($xml),
        ]);

        if (! $response->ok()) {
            throw new FisIntegrationException('FIS Gateway send failed: HTTP '.$response->status());
        }
        $data = $response->json();
        return new FisTransportResult((bool) ($data['ok'] ?? false), $data['package_id'] ?? null, $data['status'] ?? null, $data['error_code'] ?? null, $data['message'] ?? null, ['transport' => 'gateway']);
    }

    public function status(FisOutboundPackage $package): FisTransportResult
    {
        $response = $this->client()->post('/fis/test/status', [
            'request_id' => $package->request_id,
            'package_id' => $package->package_id,
        ]);
        if (! $response->ok()) {
            throw new FisIntegrationException('FIS Gateway status failed: HTTP '.$response->status());
        }
        $data = $response->json();
        return new FisTransportResult((bool) ($data['ok'] ?? false), $package->package_id, $data['status'] ?? null, $data['error_code'] ?? null, $data['message'] ?? null, ['transport' => 'gateway']);
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        $url = rtrim((string) config('fis_api.gateway_url'), '/');
        $token = (string) config('fis_api.gateway_token');
        if ($url === '' || $token === '') {
            throw new FisIntegrationException('FIS Gateway URL/token are not configured.');
        }
        return Http::baseUrl($url)
            ->acceptJson()
            ->asJson()
            ->withToken($token)
            ->timeout((int) config('fis_api.request_timeout', 30))
            ->connectTimeout((int) config('fis_api.connect_timeout', 5));
    }
}
