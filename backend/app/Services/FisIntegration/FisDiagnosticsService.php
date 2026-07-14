<?php

namespace App\Services\FisIntegration;

use App\Services\FisIntegration\Exceptions\FisIntegrationException;

class FisDiagnosticsService
{
    public function __construct(
        private FisSpecificationRegistry $registry,
        private GatewayFisTransport $gateway,
        private FisInfrastructureProbe $infrastructure,
    ) {}

    public function snapshot(bool $probeGateway = false): array
    {
        $analysis = $this->registry->analysis();
        $registry = $this->registry->inventory();
        $infrastructure = $this->infrastructure->snapshot($probeGateway);
        $contractVerified = (bool) ($registry['bundle']['verified'] ?? false);
        $gatewayProtected = $this->gatewayProtectedConfigurationReady();
        $authenticationConfirmed = (bool) config('fis_api.authentication_confirmed', false);
        $confirmedReadOnlyOperations = array_values(array_filter((array) config('fis_api.confirmed_read_only_operations', [])));
        $productionDrift = (bool) config('fis_api.allow_production_send', false)
            || config('fis_api.mode') === 'production';
        $checks = $infrastructure['checks'];

        $checks['production_guard'] = $productionDrift
            ? $this->state('failed', 'Production configuration drift detected. Diagnostics and SOAP calls remain blocked.')
            : $this->state('ok', 'Production mode and production send are disabled.');
        $checks['soap'] = $this->soapState($analysis, $registry);
        $checks['auth'] = $this->authState($gatewayProtected, $authenticationConfirmed, $analysis);
        $checks['dictionary'] = $confirmedReadOnlyOperations
            ? $this->state('contract_confirmed', 'Read-only operation identifiers are configured from the approved contract.', ['operations' => $confirmedReadOnlyOperations])
            : $this->state('blocked', 'No read-only SOAP operation is confirmed by the official contract.');

        if ($probeGateway && $gatewayProtected && ($checks['gateway_port']['status'] ?? null) === 'ok') {
            $checks['gateway_adapter'] = $this->probe(fn () => $this->gateway->adapterHealth());
            $checks['zkspd'] = $checks['gateway_adapter'];
        } elseif (! $gatewayProtected) {
            $checks['gateway_adapter'] = $this->state('blocked', 'Protected adapter health requires TEST-only Gateway configuration and HMAC.');
            $checks['zkspd'] = $this->state('blocked', 'ViPNet/ZKSPD cannot be inferred without a signed Gateway adapter health response.');
        }

        $readOnlyReady = $contractVerified
            && $authenticationConfirmed
            && $confirmedReadOnlyOperations !== []
            && $gatewayProtected
            && ($checks['gateway_health']['status'] ?? null) === 'ok'
            && ($checks['gateway_adapter']['status'] ?? null) === 'ok';
        $checks['read_only'] = $readOnlyReady
            ? $this->state('ready_for_permit', 'Prerequisites are satisfied; a separate one-time permit is still required before a controlled call.')
            : $this->state('blocked', 'The first read-only SOAP call is blocked until contract, authentication, Gateway and route evidence are confirmed.');

        $blockers = array_values(array_unique(array_merge(
            $infrastructure['blockers'] ?? [],
            $registry['bundle']['blockers'] ?? [],
            $productionDrift ? ['production_configuration_drift'] : [],
            $gatewayProtected ? [] : ['gateway_hmac_or_test_configuration_missing'],
            ($checks['gateway_health']['status'] ?? null) === 'ok' ? [] : ['gateway_health_unconfirmed'],
            ($checks['gateway_adapter']['status'] ?? null) === 'ok' ? [] : ['gateway_fis_adapter_unconfirmed'],
            $authenticationConfirmed ? [] : ['fis_authentication_unknown'],
            $confirmedReadOnlyOperations ? [] : ['read_only_operation_unconfirmed'],
            $readOnlyReady ? ['one_time_probe_permit_not_implemented'] : [],
        )));

        return [
            'checked_at' => now()->toISOString(),
            'environment' => 'test',
            'production_enabled' => $productionDrift,
            'capability_state' => $readOnlyReady ? 'awaiting_permit' : 'observed',
            'stop_gate' => true,
            'blockers' => $blockers,
            'contract' => $analysis,
            'registry' => $registry,
            'checks' => $checks,
        ];
    }

    private function gatewayProtectedConfigurationReady(): bool
    {
        return (bool) config('fis_api.gateway_enabled')
            && filled(config('fis_api.gateway_url'))
            && filled(config('fis_api.gateway_shared_secret'))
            && config('fis_api.gateway_allowed_environment') === 'test';
    }

    private function probe(callable $callback): array
    {
        try {
            $result = $callback();
            $ok = (bool) ($result['ok'] ?? false);

            return $this->state(
                $ok ? 'ok' : 'failed',
                $ok ? 'Gateway adapter health succeeded.' : 'Gateway adapter health reported a failure.',
                [
                    'error_code' => $result['error_code'] ?? null,
                    'latency_ms' => $result['latency_ms'] ?? null,
                    'gateway_version' => $result['gateway_version'] ?? null,
                ],
            );
        } catch (FisIntegrationException) {
            return $this->state('failed', 'Gateway adapter health request failed.', ['error_code' => 'gateway_adapter_request_failed']);
        }
    }

    private function soapState(array $analysis, array $registry): array
    {
        if (($registry['counts']['wsdl'] ?? 0) === 0) {
            return $this->state('blocked', 'Official WSDL is absent; SOAP version, binding, port, actions and operations are unconfirmed.');
        }

        if (! ($registry['bundle']['verified'] ?? false)) {
            return $this->state('parsed_unverified', 'Contract artifacts were parsed, but bundle integrity and approval are incomplete.', [
                'versions' => $analysis['soap_versions'] ?? [],
                'bindings' => count($analysis['bindings'] ?? []),
                'operations' => count($analysis['operations'] ?? []),
            ]);
        }

        return $this->state('contract_verified', 'SOAP metadata is backed by the approved immutable contract bundle.', [
            'versions' => $analysis['soap_versions'] ?? [],
            'bindings' => count($analysis['bindings'] ?? []),
            'operations' => count($analysis['operations'] ?? []),
        ]);
    }

    private function authState(bool $gatewayProtected, bool $authenticationConfirmed, array $analysis): array
    {
        if (! $gatewayProtected) {
            return $this->state('blocked', 'Portal-to-Gateway HMAC or TEST-only Gateway configuration is missing.');
        }

        if (! $authenticationConfirmed) {
            return $this->state('blocked', 'Gateway HMAC is configured, but FIS authentication is not confirmed by the official contract.', [
                'fis_authentication' => $analysis['authentication'] ?? 'unknown',
            ]);
        }

        return $this->state('confirmed', 'Portal-to-Gateway HMAC and FIS authentication evidence are confirmed.');
    }

    private function state(string $status, string $message, array $details = []): array
    {
        return ['status' => $status, 'message' => $message, 'details' => $details];
    }
}
