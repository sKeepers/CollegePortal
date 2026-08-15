<?php

namespace App\Services\FisIntegration;

use App\Services\FisIntegration\Exceptions\FisIntegrationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class CollegePortalGatewayClient
{
    public function __construct(private ?GatewayRequestSigner $signer = null)
    {
        $this->signer ??= new GatewayRequestSigner();
    }

    public function health(): array { return $this->get('/health'); }
    public function version(): array { return $this->get('/version'); }
    public function capabilities(): array { return $this->get('/capabilities'); }
    public function listAdapters(): array { return $this->get('/adapters'); }
    public function adapterHealth(string $adapter): array { return $this->get('/adapters/'.rawurlencode($adapter).'/health'); }
    public function runDiagnostics(): array { return $this->post('/diagnostics/run', []); }
    public function latestDiagnostics(): array { return $this->get('/diagnostics/latest'); }

    /**
     * Читающие методы подписываются наравне с пишущими. Без подписи шлюз отвечает
     * `auth_required: Missing HMAC headers` на всё, что закрыто, — а закрыты у него
     * здоровье адаптера и последняя диагностика. Открытыми остаются только `/health`,
     * `/version`, `/capabilities` и `/adapters`, поэтому отсутствие подписи здесь
     * замечалось не сразу: четыре метода из шести работали.
     */
    public function get(string $path): array
    {
        $this->ensureGatewayEnabled();
        try { $response = $this->client()->withHeaders($this->signer->headers('GET', $path, ''))->get($path); }
        catch (ConnectionException $exception) { throw new FisIntegrationException('CollegePortal Gateway connection failed: '.$this->redact($exception->getMessage())); }
        return $this->responseData($response, 'CollegePortal Gateway request failed');
    }

    public function post(string $path, array $payload): array
    {
        $this->ensureGatewayEnabled();
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) throw new FisIntegrationException('CollegePortal Gateway payload cannot be encoded.');
        try {
            $response = $this->client()->withHeaders($this->signer->headers('POST', $path, $body))->withBody($body, 'application/json')->post($path);
        } catch (ConnectionException $exception) {
            throw new FisIntegrationException('CollegePortal Gateway connection failed: '.$this->redact($exception->getMessage()));
        }
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
}
