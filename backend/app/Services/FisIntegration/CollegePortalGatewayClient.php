<?php

namespace App\Services\FisIntegration;

use App\Services\FisIntegration\Exceptions\FisIntegrationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CollegePortalGatewayClient
{
    public function __construct(
        private ?GatewayRequestSigner $signer = null,
        private ?FisCommunicationLogger $communicationLogger = null,
    )
    {
        $this->signer ??= new GatewayRequestSigner();
        $this->communicationLogger ??= new FisCommunicationLogger();
    }

    public function health(): array { return $this->get('/health'); }
    public function version(): array { return $this->get('/version'); }
    public function capabilities(): array { return $this->get('/capabilities'); }
    public function listAdapters(): array { return $this->get('/adapters'); }
    public function adapterHealth(string $adapter): array { return $this->get('/adapters/'.rawurlencode($adapter).'/health'); }
    public function runDiagnostics(): array { return $this->post('/diagnostics/run', []); }
    public function latestDiagnostics(): array { return $this->get('/diagnostics/latest'); }

    public function get(string $path): array
    {
        $this->ensureGatewayEnabled();
        $started = microtime(true);
        $headers = in_array($path, ['/health', '/version', '/capabilities', '/adapters'], true)
            ? [GatewayRequestSigner::HEADER_REQUEST_ID => (string) Str::uuid()]
            : $this->signer->headers('GET', $path, '');
        try {
            $response = $this->client()->withHeaders($headers)->get($path);
        } catch (ConnectionException $exception) {
            $this->record('GET', $path, $headers, $started, null, ['error_code' => 'connection_failed']);
            throw new FisIntegrationException('CollegePortal Gateway connection failed: '.$this->redact($exception->getMessage()));
        }
        $data = $response->json();
        $this->record('GET', $path, $headers, $started, $response->status(), is_array($data) ? $data : ['error_code' => 'invalid_json']);
        return $this->responseData($response, 'CollegePortal Gateway request failed');
    }

    public function post(string $path, array $payload): array
    {
        $this->ensureGatewayEnabled();
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) throw new FisIntegrationException('CollegePortal Gateway payload cannot be encoded.');
        $headers = $this->signer->headers('POST', $path, $body);
        $started = microtime(true);
        try {
            $response = $this->client()->withHeaders($headers)->withBody($body, 'application/json')->post($path);
        } catch (ConnectionException $exception) {
            $this->record('POST', $path, $headers, $started, null, ['error_code' => 'connection_failed']);
            throw new FisIntegrationException('CollegePortal Gateway connection failed: '.$this->redact($exception->getMessage()));
        }
        $data = $response->json();
        $this->record('POST', $path, $headers, $started, $response->status(), is_array($data) ? $data : ['error_code' => 'invalid_json']);
        return $this->responseData($response, 'CollegePortal Gateway request failed');
    }

    private function client(): PendingRequest
    {
        $url = rtrim((string) config('fis_api.gateway_url'), '/');
        if ($url === '') throw new FisIntegrationException('CollegePortal Gateway URL is not configured.');
        return Http::baseUrl($url)->acceptJson()->timeout((int) config('fis_api.gateway_request_timeout', config('fis_api.request_timeout', 30)))->connectTimeout((int) config('fis_api.gateway_connect_timeout', config('fis_api.connect_timeout', 5)));
    }

    private function responseData(\Illuminate\Http\Client\Response $response, string $message): array
    {
        $data = $response->json();
        if (! is_array($data)) throw new FisIntegrationException($message.': invalid JSON response');
        if (! $response->ok()) {
            $code = $data['error_code'] ?? ('http_'.$response->status());
            $text = $data['message'] ?? $message;
            throw new FisIntegrationException($code.': '.$this->redact((string) $text));
        }
        return $data;
    }

    private function ensureGatewayEnabled(): void
    {
        if (! (bool) config('fis_api.gateway_enabled')) throw new FisIntegrationException('CollegePortal Gateway is disabled. Set FIS_GATEWAY_ENABLED=true for TEST diagnostics.');
        if (config('fis_api.gateway_allowed_environment') !== 'test') throw new FisIntegrationException('CollegePortal Gateway is allowed only for TEST environment.');
    }

    private function redact(string $message): string
    {
        return preg_replace('/(Authorization|Signature|secret|token|password)[^\s,;]*/i', '$1=[redacted]', $message) ?? $message;
    }

    private function record(string $method, string $path, array $headers, float $started, ?int $httpCode, array $data): void
    {
        $ok = $httpCode !== null && $httpCode >= 200 && $httpCode < 300 && ($data['ok'] ?? true) !== false;
        $this->communicationLogger->record([
            'request_id' => $headers[GatewayRequestSigner::HEADER_REQUEST_ID] ?? null,
            'method' => $method.' '.$path,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'status' => $ok ? 'ok' : 'failed',
            'http_code' => $httpCode,
            'soap_fault_code' => $data['soap_fault_code'] ?? $data['fault_code'] ?? null,
            'soap_fault_message' => $data['soap_fault_message'] ?? $data['fault_message'] ?? null,
            'error_code' => $data['error_code'] ?? null,
            'metadata' => [
                'gateway_version' => $data['gateway_version'] ?? null,
                'latency_ms' => $data['latency_ms'] ?? null,
                'operation' => $path,
                'endpoint_class' => 'test',
            ],
        ]);
    }
}
