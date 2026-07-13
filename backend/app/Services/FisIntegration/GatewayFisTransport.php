<?php

namespace App\Services\FisIntegration;

use App\Models\FisOutboundPackage;
use App\Services\FisIntegration\Dto\FisTransportResult;
use App\Services\FisIntegration\Exceptions\FisIntegrationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class GatewayFisTransport implements FisTransportInterface
{
    public function __construct(private ?GatewayRequestSigner $signer = null)
    {
        $this->signer ??= new GatewayRequestSigner();
    }

    public function send(FisOutboundPackage $package, string $xml): FisTransportResult
    {
        $this->ensureGatewayEnabled();
        $data = $this->post('/fis/test/import', [
            'request_id' => $package->request_id,
            'package_hash' => hash('sha256', $xml),
            'package_type' => $package->package_type,
            'schema_version' => $package->schema_version,
            'payload' => base64_encode($xml),
        ]);

        return new FisTransportResult(
            (bool) ($data['ok'] ?? false),
            $data['package_id'] ?? null,
            $data['status'] ?? null,
            $data['error_code'] ?? null,
            $data['message'] ?? null,
            ['transport' => 'gateway', 'gateway_version' => $data['gateway_version'] ?? null]
        );
    }

    public function status(FisOutboundPackage $package): FisTransportResult
    {
        $this->ensureGatewayEnabled();
        $data = $this->post('/fis/test/import-result', [
            'request_id' => $package->request_id,
            'package_id' => $package->package_id,
        ]);

        return new FisTransportResult(
            (bool) ($data['ok'] ?? false),
            $package->package_id,
            $data['status'] ?? null,
            $data['error_code'] ?? null,
            $data['message'] ?? null,
            ['transport' => 'gateway', 'gateway_version' => $data['gateway_version'] ?? null]
        );
    }

    public function health(): array
    {
        return $this->get('/health');
    }

    public function version(): array
    {
        return $this->get('/version');
    }

    public function zkspdCheck(): array
    {
        return $this->post('/zkspd/check', []);
    }

    public function dictionariesList(): array
    {
        return $this->post('/fis/test/dictionaries/list', []);
    }

    public function dictionaryDetails(?string $code = null): array
    {
        return $this->post('/fis/test/dictionaries/details', ['code' => $code]);
    }

    public function institutionInfo(): array
    {
        return $this->post('/fis/test/institution/info', []);
    }

    public function testCheckApplication(array $payload = []): array
    {
        return $this->post('/fis/test/check-application', $payload);
    }

    public function validateDisabled(): array
    {
        return $this->post('/fis/test/validate', []);
    }

    public function importDisabled(): array
    {
        return $this->post('/fis/test/import', []);
    }

    private function get(string $path): array
    {
        $this->ensureGatewayEnabled();
        $response = $this->client()->get($path);
        return $this->responseData($response, 'FIS Gateway request failed');
    }

    private function post(string $path, array $payload): array
    {
        $this->ensureGatewayEnabled();
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new FisIntegrationException('FIS Gateway payload cannot be encoded.');
        }

        try {
            $response = $this->client()
                ->withHeaders($this->signer->headers('POST', $path, $body))
                ->withBody($body, 'application/json')
                ->post($path);
        } catch (ConnectionException $exception) {
            throw new FisIntegrationException('FIS Gateway connection failed: '.$this->redact($exception->getMessage()));
        }

        return $this->responseData($response, 'FIS Gateway request failed');
    }

    private function client(): PendingRequest
    {
        $url = rtrim((string) config('fis_api.gateway_url'), '/');
        if ($url === '') {
            throw new FisIntegrationException('FIS Gateway URL is not configured.');
        }

        return Http::baseUrl($url)
            ->acceptJson()
            ->timeout((int) config('fis_api.gateway_request_timeout', config('fis_api.request_timeout', 30)))
            ->connectTimeout((int) config('fis_api.gateway_connect_timeout', config('fis_api.connect_timeout', 5)));
    }

    private function responseData(\Illuminate\Http\Client\Response $response, string $message): array
    {
        $data = $response->json();
        if (! is_array($data)) {
            throw new FisIntegrationException($message.': invalid JSON response');
        }

        if (! $response->ok()) {
            $code = $data['error_code'] ?? ('http_'.$response->status());
            $text = $data['message'] ?? $message;
            throw new FisIntegrationException($code.': '.$this->redact((string) $text));
        }

        return $data;
    }

    private function ensureGatewayEnabled(): void
    {
        if (! (bool) config('fis_api.gateway_enabled')) {
            throw new FisIntegrationException('FIS Gateway is disabled. Set FIS_GATEWAY_ENABLED=true for TEST diagnostics.');
        }
        if (config('fis_api.gateway_allowed_environment') !== 'test') {
            throw new FisIntegrationException('FIS Gateway is allowed only for TEST environment.');
        }
    }

    private function redact(string $message): string
    {
        return preg_replace('/(Authorization|Signature|secret|token|password)[^\s,;]*/i', '$1=[redacted]', $message) ?? $message;
    }
}
