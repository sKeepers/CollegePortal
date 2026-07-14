<?php

namespace Tests\Feature;

use App\Models\FisCommunicationLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FisDiagnosticsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnostics_reports_stop_gate_without_official_wsdl(): void
    {
        $this->withApiAuth($this->userWithPermission('fis.outbound.view'));
        config([
            'fis_api.wsdl_path' => null,
            'fis_api.xsd_path' => null,
            'fis_api.gateway_enabled' => false,
            'fis_api.gateway_url' => null,
        ]);

        $this->getJson('/api/fis/diagnostics')
            ->assertOk()
            ->assertJsonPath('data.stop_gate', true)
            ->assertJsonPath('data.contract.status', 'missing')
            ->assertJsonPath('data.checks.soap.status', 'blocked')
            ->assertJsonPath('data.production_enabled', false);
    }

    public function test_gateway_probe_is_logged_without_request_payload_or_personal_data(): void
    {
        $this->withApiAuth($this->userWithPermission('fis.outbound.view'));
        config([
            'fis_api.gateway_enabled' => true,
            'fis_api.gateway_allowed_environment' => 'test',
            'fis_api.gateway_url' => 'http://gateway.test',
            'fis_api.gateway_shared_secret' => 'gateway-secret',
            'fis_api.wsdl_path' => null,
        ]);
        Http::fake([
            'gateway.test/health' => Http::response(['ok' => true, 'message' => 'healthy'], 200),
            'gateway.test/version' => Http::response(['ok' => true, 'gateway_version' => '0.2.0-dev'], 200),
            'gateway.test/capabilities' => Http::response(['ok' => true, 'adapters' => ['fis' => []]], 200),
            'gateway.test/adapters/fis/health' => Http::response(['ok' => false, 'error_code' => 'zkspd_unreachable', 'message' => 'timeout'], 200),
            'gateway.test/adapters/fis/zkspd/check' => Http::response(['ok' => false, 'error_code' => 'zkspd_unreachable', 'message' => 'timeout'], 200),
        ]);

        $this->postJson('/api/fis/diagnostics/run')
            ->assertOk()
            ->assertJsonPath('data.checks.gateway.status', 'ok')
            ->assertJsonPath('data.checks.zkspd.status', 'failed');

        $this->assertDatabaseHas('fis_communication_logs', ['method' => 'GET /health', 'status' => 'ok', 'http_code' => 200]);
        $this->assertDatabaseHas('fis_communication_logs', ['method' => 'POST /adapters/fis/zkspd/check', 'status' => 'failed', 'error_code' => 'zkspd_unreachable']);
        $serialized = FisCommunicationLog::query()->get()->toJson();
        $this->assertStringNotContainsString('gateway-secret', $serialized);
        $this->assertStringNotContainsString('payload', $serialized);
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
