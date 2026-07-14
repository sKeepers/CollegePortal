<?php

namespace App\Services\FisIntegration;

use App\Services\FisIntegration\Exceptions\FisIntegrationException;

class FisDiagnosticsService
{
    public function __construct(
        private FisSpecificationRegistry $registry,
        private GatewayFisTransport $gateway,
    ) {}

    public function snapshot(bool $probeGateway = false): array
    {
        $analysis = $this->registry->analysis();
        $gatewayConfigured = (bool) config('fis_api.gateway_enabled') && filled(config('fis_api.gateway_url'));
        $contractLoaded = ($analysis['status'] ?? 'missing') === 'loaded';

        $checks = [
            'gateway' => $this->state($gatewayConfigured ? 'configured' : 'blocked', $gatewayConfigured ? 'Gateway configured for TEST.' : 'Gateway URL or feature flag is not configured.'),
            'test_endpoint' => $this->endpointState((string) config('fis_api.test_endpoint')),
            'soap' => $this->soapState($analysis),
            'tls' => $this->tlsState(),
            'auth' => $this->authState($analysis),
            'dictionary' => $this->state($contractLoaded ? 'ready_for_probe' : 'blocked', $contractLoaded ? 'A confirmed dictionary method must be selected from the official method map.' : 'Official WSDL method map is not loaded.'),
            'read_only' => $this->state($contractLoaded ? 'ready_for_probe' : 'blocked', $contractLoaded ? 'A confirmed read-only operation can be executed after method mapping.' : 'Read-only SOAP call is blocked until WSDL/DISCO verification.'),
        ];

        if ($probeGateway && $gatewayConfigured) {
            $checks['gateway'] = $this->probe(fn () => $this->gateway->health());
            $checks['gateway_version'] = $this->probe(fn () => $this->gateway->version());
            $checks['gateway_capabilities'] = $this->probe(fn () => $this->gateway->capabilities());
            $checks['gateway_adapter'] = $this->probe(fn () => $this->gateway->adapterHealth());
            $checks['zkspd'] = $this->probe(fn () => $this->gateway->zkspdCheck());
        }

        $probeFailed = $probeGateway && collect(['gateway', 'gateway_adapter', 'zkspd'])
            ->contains(fn (string $key) => ($checks[$key]['status'] ?? null) === 'failed');
        $readOnlyConfirmed = ($checks['read_only']['status'] ?? null) === 'ok';

        return [
            'checked_at' => now()->toISOString(),
            'environment' => 'test',
            'production_enabled' => false,
            'stop_gate' => ! $contractLoaded || ! $gatewayConfigured || ! $readOnlyConfirmed || $probeFailed,
            'contract' => $analysis,
            'checks' => $checks,
        ];
    }

    private function probe(callable $callback): array
    {
        try {
            $result = $callback();
            $ok = (bool) ($result['ok'] ?? false);
            return $this->state($ok ? 'ok' : 'failed', (string) ($result['message'] ?? ($ok ? 'Check passed.' : 'Check failed.')), $result);
        } catch (FisIntegrationException $exception) {
            return $this->state('failed', $exception->getMessage());
        }
    }

    private function endpointState(string $endpoint): array
    {
        $parts = parse_url($endpoint);
        return $this->state($endpoint !== '' ? 'configured' : 'blocked', $endpoint !== '' ? 'TEST endpoint configured; production is hard-disabled.' : 'TEST endpoint is not configured.', [
            'scheme' => $parts['scheme'] ?? null,
            'host' => $parts['host'] ?? null,
            'port' => $parts['port'] ?? null,
        ]);
    }

    private function soapState(array $analysis): array
    {
        if (($analysis['status'] ?? null) !== 'loaded') {
            return $this->state('blocked', 'SOAP version, bindings and actions cannot be confirmed without official WSDL.');
        }

        return $this->state('confirmed', 'SOAP metadata parsed from the loaded WSDL.', [
            'versions' => $analysis['soap_versions'] ?? [],
            'bindings' => count($analysis['bindings'] ?? []),
            'operations' => count($analysis['operations'] ?? []),
        ]);
    }

    private function tlsState(): array
    {
        $fisScheme = parse_url((string) config('fis_api.test_endpoint'), PHP_URL_SCHEME);
        $gatewayScheme = parse_url((string) config('fis_api.gateway_url'), PHP_URL_SCHEME);
        return $this->state('observed', 'TLS is not inferred: FIS TEST is inside ZKSPD and Portal-to-Gateway integrity uses HMAC.', [
            'fis_test_scheme' => $fisScheme,
            'gateway_scheme' => $gatewayScheme,
        ]);
    }

    private function authState(array $analysis): array
    {
        return $this->state(filled(config('fis_api.gateway_shared_secret')) ? 'gateway_hmac_configured' : 'blocked', filled(config('fis_api.gateway_shared_secret')) ? 'Portal-to-Gateway HMAC is configured. FIS authentication still requires official contract confirmation.' : 'Gateway HMAC secret is not configured.', [
            'fis_authentication' => $analysis['authentication'] ?? 'unknown',
        ]);
    }

    private function state(string $status, string $message, array $details = []): array
    {
        return ['status' => $status, 'message' => $message, 'details' => $details];
    }
}
