<?php

namespace Tests\Feature;

use App\Models\FisCommunicationLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\FisIntegration\FisCommunicationLogger;
use App\Services\FisIntegration\FisInfrastructureProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FisDiagnosticsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnostics_reports_xml_http_stop_gate_without_official_xsd(): void
    {
        $this->withApiAuth($this->userWithPermission('fis.outbound.view'));
        config([
            'fis_api.wsdl_path' => null,
            'fis_api.xsd_path' => null,
            'fis_api.disco_path' => null,
            'fis_api.spec_registry_path' => storage_path('framework/testing/fis-registry-missing'),
            'fis_api.gateway_enabled' => false,
            'fis_api.gateway_url' => null,
            'fis_api.gateway_diagnostics_url' => null,
            'fis_api.contract_verified' => false,
            'fis_api.authentication_confirmed' => false,
            'fis_api.confirmed_read_only_operations' => [],
        ]);

        $this->getJson('/api/fis/diagnostics')
            ->assertOk()
            ->assertJsonPath('data.stop_gate', true)
            ->assertJsonPath('data.capability_state', 'observed')
            ->assertJsonPath('data.contract.status', 'missing')
            ->assertJsonPath('data.protocol', 'xml_over_http')
            ->assertJsonPath('data.checks.protocol.status', 'confirmed')
            ->assertJsonPath('data.checks.soap.status', 'not_applicable')
            ->assertJsonPath('data.checks.xsd.status', 'blocked')
            ->assertJsonPath('data.checks.read_only.status', 'blocked')
            ->assertJsonPath('data.production_enabled', false)
            ->assertJsonFragment(['official_xsd_missing'])
            ->assertJsonFragment(['read_only_xml_operation_unconfirmed']);
    }

    public function test_gateway_probe_reports_public_endpoints_and_protected_adapter_without_guessing_soap(): void
    {
        $this->withApiAuth($this->userWithPermission('fis.outbound.view'));
        config([
            'fis_api.gateway_enabled' => true,
            'fis_api.gateway_allowed_environment' => 'test',
            'fis_api.gateway_url' => 'http://gateway.test:8099',
            'fis_api.gateway_diagnostics_url' => 'http://gateway.test:8099',
            'fis_api.gateway_shared_secret' => 'gateway-secret',
            'fis_api.wsdl_path' => null,
            'fis_api.xsd_path' => null,
            'fis_api.disco_path' => null,
            'fis_api.spec_registry_path' => storage_path('framework/testing/fis-registry-missing'),
            'fis_api.contract_verified' => false,
            'fis_api.authentication_confirmed' => false,
            'fis_api.confirmed_read_only_operations' => [],
        ]);
        $this->app->instance(FisInfrastructureProbe::class, new FisInfrastructureProbe(
            static fn (string $host, int $port, float $timeout): array => [
                'connected' => true,
                'errno' => 0,
                'error' => '',
                'latency_ms' => 4,
            ],
            $this->app->make(FisCommunicationLogger::class),
        ));
        Http::fake([
            'gateway.test:8099/health' => Http::response(['ok' => true, 'message' => 'healthy'], 200),
            'gateway.test:8099/version' => Http::response(['ok' => true, 'gateway_version' => '0.2.0-dev'], 200),
            'gateway.test:8099/adapters' => Http::response(['ok' => true, 'adapters' => [['name' => 'fis']]], 200),
            'gateway.test:8099/adapters/fis/health' => Http::response(['ok' => false, 'error_code' => 'fis_test_tcp_timeout', 'latency_ms' => 5000], 200),
        ]);

        $this->postJson('/api/fis/diagnostics/run')
            ->assertOk()
            ->assertJsonPath('data.stop_gate', true)
            ->assertJsonPath('data.checks.gateway_port.status', 'ok')
            ->assertJsonPath('data.checks.gateway_service.status', 'running')
            ->assertJsonPath('data.checks.gateway_health.status', 'ok')
            ->assertJsonPath('data.checks.gateway_version.details.gateway_version', '0.2.0-dev')
            ->assertJsonPath('data.checks.gateway_adapter.status', 'failed')
            ->assertJsonPath('data.checks.zkspd.status', 'failed')
            ->assertJsonPath('data.checks.read_only.status', 'blocked');

        $this->assertDatabaseHas('fis_communication_logs', ['method' => 'GET /health', 'status' => 'ok', 'http_code' => 200]);
        $this->assertDatabaseHas('fis_communication_logs', ['method' => 'GET /adapters/fis/health', 'status' => 'failed', 'error_code' => 'fis_test_tcp_timeout']);
        $serialized = FisCommunicationLog::query()->get()->toJson();
        $this->assertStringNotContainsString('gateway-secret', $serialized);
        $this->assertStringNotContainsString('payload', $serialized);
    }

    public function test_tcp_refusal_is_reported_as_observed_evidence_not_as_a_stopped_windows_service(): void
    {
        $this->withApiAuth($this->userWithPermission('fis.outbound.view'));
        config([
            'fis_api.gateway_diagnostics_url' => 'http://192.168.34.223:8099',
            'fis_api.gateway_enabled' => false,
            'fis_api.spec_registry_path' => storage_path('framework/testing/fis-registry-missing'),
        ]);
        $this->app->instance(FisInfrastructureProbe::class, new FisInfrastructureProbe(
            static fn (string $host, int $port, float $timeout): array => $host === '192.168.34.223'
                ? ['connected' => false, 'errno' => 111, 'error' => 'Connection refused', 'latency_ms' => 1]
                : ['connected' => false, 'errno' => 110, 'error' => 'Connection timed out', 'latency_ms' => 5000],
            $this->app->make(FisCommunicationLogger::class),
        ));

        $this->postJson('/api/fis/diagnostics/run')
            ->assertOk()
            ->assertJsonPath('data.checks.gateway_host.status', 'observed')
            ->assertJsonPath('data.checks.gateway_port.details.error_code', 'tcp_refused')
            ->assertJsonPath('data.checks.gateway_service.status', 'unknown')
            ->assertJsonPath('data.checks.fis_test_direct.details.error_code', 'tcp_timeout');
    }

    public function test_fault_text_is_never_persisted_and_only_its_hash_is_exposed(): void
    {
        $fault = '<Fault>student@example.test token=secret-value</Fault>';
        $this->app->make(FisCommunicationLogger::class)->record([
            'request_id' => 'request-1',
            'method' => 'TEST',
            'status' => 'failed',
            'soap_fault_code' => 'Client',
            'soap_fault_message' => $fault,
        ]);

        $log = FisCommunicationLog::query()->firstOrFail();
        $this->assertNull($log->soap_fault_message);
        $this->assertSame(hash('sha256', $fault), $log->metadata['xml_fault_hash']);
        $this->assertStringNotContainsString('student@example.test', $log->toJson());
        $this->assertStringNotContainsString('secret-value', $log->toJson());
    }

    public function test_production_configuration_drift_keeps_the_stop_gate_closed(): void
    {
        $this->withApiAuth($this->userWithPermission('fis.outbound.view'));
        config([
            'fis_api.allow_production_send' => true,
            'fis_api.spec_registry_path' => storage_path('framework/testing/fis-registry-missing'),
        ]);

        $this->getJson('/api/fis/diagnostics')
            ->assertOk()
            ->assertJsonPath('data.stop_gate', true)
            ->assertJsonPath('data.production_enabled', true)
            ->assertJsonPath('data.checks.production_guard.status', 'failed')
            ->assertJsonFragment(['production_configuration_drift']);
    }

    public function test_diagnostics_requires_permission(): void
    {
        $this->withApiAuth(User::factory()->create());
        $this->getJson('/api/fis/diagnostics')->assertForbidden();
    }

    private function userWithPermission(string $code): User
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'FIS diagnostics', 'code' => 'fis_diagnostics_'.uniqid(), 'description' => null]);
        $permission = Permission::firstOrCreate(['code' => $code], ['name' => $code, 'module' => 'FIS', 'description' => $code, 'system' => true, 'active' => true]);
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);

        return $user;
    }
}
