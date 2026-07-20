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
        $configuredReadOnlyOperation = (string) config('fis_api.read_only_operation', 'GetTestDictionariesList');
        $productionDrift = (bool) config('fis_api.allow_production_send', false)
            || config('fis_api.mode') === 'production'
            || (bool) config('fis_api.enable_mutating_operations', false);
        $checks = $infrastructure['checks'];

        $checks['protocol'] = $this->state('confirmed', 'Официальная модель ФИС подтверждена как XML-over-HTTP: HTTP POST с XML body, без SOAP envelope, WSDL, binding и SOAPAction.', [
            'protocol' => 'xml_over_http',
            'http_method' => config('fis_api.http_method', 'POST'),
            'content_type' => config('fis_api.content_type'),
            'test_endpoint' => config('fis_api.test_endpoint'),
            'soap' => 'not_used',
        ]);
        $checks['production_guard'] = $productionDrift
            ? $this->state('failed', 'Production configuration drift detected. TEST diagnostics and XML-over-HTTP calls remain blocked.')
            : $this->state('ok', 'Production mode, production send and mutating operations are disabled.');
        $checks['xsd'] = $this->xsdState($registry);
        $checks['soap'] = $this->state('not_applicable', 'WSDL/DISCO/SOAPAction не требуются: официальный ответ ФИС подтвердил XML-over-HTTP без SOAP-контракта.');
        $checks['auth'] = $this->authState($gatewayProtected, $authenticationConfirmed, $analysis);
        $checks['dictionary'] = in_array($configuredReadOnlyOperation, $confirmedReadOnlyOperations, true)
            ? $this->state('contract_confirmed', 'Read-only XML operation is configured from the approved XSD/specification.', ['operation' => $configuredReadOnlyOperation])
            : $this->state('blocked', 'Read-only XML operation is not confirmed by the official XSD/specification.', ['requested_operation' => $configuredReadOnlyOperation]);

        if ($probeGateway && $gatewayProtected && ($checks['gateway_port']['status'] ?? null) === 'ok') {
            $checks['gateway_adapter'] = $this->probe(fn () => $this->gateway->adapterHealth());
            $checks['zkspd'] = $checks['gateway_adapter'];
        } elseif (! $gatewayProtected) {
            $checks['gateway_adapter'] = $this->state('blocked', 'Protected adapter health requires TEST-only Gateway configuration and HMAC.');
            $checks['zkspd'] = $this->state('blocked', 'ViPNet/ZKSPD cannot be inferred without a signed Gateway adapter health response.');
        }

        $readOnlyReady = $contractVerified
            && $authenticationConfirmed
            && in_array($configuredReadOnlyOperation, $confirmedReadOnlyOperations, true)
            && $gatewayProtected
            && ($checks['gateway_health']['status'] ?? null) === 'ok'
            && ($checks['gateway_adapter']['status'] ?? null) === 'ok';
        $checks['read_only'] = $readOnlyReady
            ? $this->state('ready_for_permit', 'Prerequisites are satisfied; a separate one-time permit is still required before one controlled TEST read-only XML-over-HTTP call.')
            : $this->state('blocked', 'The first read-only TEST XML-over-HTTP call is blocked until XSD, authentication, Gateway and route evidence are confirmed.');

        $blockers = array_values(array_unique(array_merge(
            $infrastructure['blockers'] ?? [],
            $registry['bundle']['blockers'] ?? [],
            $productionDrift ? ['production_configuration_drift'] : [],
            $gatewayProtected ? [] : ['gateway_hmac_or_test_configuration_missing'],
            ($checks['gateway_health']['status'] ?? null) === 'ok' ? [] : ['gateway_health_unconfirmed'],
            ($checks['gateway_adapter']['status'] ?? null) === 'ok' ? [] : ['gateway_fis_adapter_unconfirmed'],
            $authenticationConfirmed ? [] : ['fis_authentication_unknown'],
            in_array($configuredReadOnlyOperation, $confirmedReadOnlyOperations, true) ? [] : ['read_only_xml_operation_unconfirmed'],
            $readOnlyReady ? ['one_time_probe_permit_not_implemented'] : [],
        )));

        return [
            'checked_at' => now()->toISOString(),
            'environment' => 'test',
            'protocol' => 'xml_over_http',
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

    private function xsdState(array $registry): array
    {
        if (($registry['counts']['xsd'] ?? 0) === 0) {
            return $this->state('blocked', 'Официальная XSD ФИС не загружена; XML root, namespaces, request/response и payload authentication не подтверждены.');
        }

        if (! ($registry['bundle']['verified'] ?? false)) {
            return $this->state('parsed_unverified', 'XSD найдена, но manifest/approval incomplete; live TEST call blocked.', [
                'xsd_count' => $registry['counts']['xsd'] ?? 0,
                'blockers' => $registry['bundle']['blockers'] ?? [],
            ]);
        }

        return $this->state('contract_verified', 'Approved XSD bundle is loaded for XML-over-HTTP validation.', [
            'xsd_count' => $registry['counts']['xsd'] ?? 0,
        ]);
    }

    private function authState(bool $gatewayProtected, bool $authenticationConfirmed, array $analysis): array
    {
        if (! $gatewayProtected) {
            return $this->state('blocked', 'Portal-to-Gateway HMAC or TEST-only Gateway configuration is missing.');
        }

        if (! $authenticationConfirmed) {
            return $this->state('blocked', 'Gateway HMAC is configured, but FIS XML payload/transport authentication is not confirmed by the official XSD/specification.', [
                'fis_authentication' => $analysis['xsd']['authentication_elements'] ?? [],
            ]);
        }

        return $this->state('confirmed', 'Portal-to-Gateway HMAC and FIS authentication evidence are confirmed.');
    }

    private function state(string $status, string $message, array $details = []): array
    {
        return ['status' => $status, 'message' => $message, 'details' => $details];
    }
}
