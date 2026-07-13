<?php

namespace Tests\Feature;

use App\Models\FisOutboundPackage;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FisOutboundApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbound_package_flow_uses_xsd_and_mock_transport(): void
    {
        Storage::fake('local');
        $user = $this->userWith(['fis.outbound.view','fis.outbound.create','fis.outbound.generate','fis.outbound.validate','fis.outbound.send_test','fis.outbound.status','fis.outbound.download']);
        $this->withApiAuth($user);

        $package = FisOutboundPackage::create(['package_type' => 'admission-campaign', 'schema_version' => 'test-schema', 'environment' => 'test', 'status' => 'generated', 'created_by' => $user->id, 'payload_path' => 'fis/outbound/test.xml']);
        Storage::disk('local')->put($package->payload_path, '<?xml version="1.0" encoding="UTF-8"?><Package><Name>Demo</Name></Package>');
        $xsd = storage_path('framework/testing/fis-test.xsd');
        if (! is_dir(dirname($xsd))) {
            mkdir(dirname($xsd), 0777, true);
        }
        file_put_contents($xsd, '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"><xs:element name="Package"><xs:complexType><xs:sequence><xs:element name="Name" type="xs:string"/></xs:sequence></xs:complexType></xs:element></xs:schema>');
        config(['fis_api.xsd_path' => $xsd]);

        $this->postJson("/api/fis/outbound/packages/{$package->id}/validate")
            ->assertOk()
            ->assertJsonPath('validation.ok', true);
        $this->postJson("/api/fis/outbound/packages/{$package->id}/send-preview")
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['request_id','payload_sha256','redacted_preview']);
        $this->postJson("/api/fis/outbound/packages/{$package->id}/send", ['mock' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');
        $this->postJson("/api/fis/outbound/packages/{$package->id}/refresh-status", ['mock' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $this->getJson("/api/fis/outbound/packages/{$package->id}/events")
            ->assertOk()
            ->assertJsonCount(4, 'data');
    }

    public function test_missing_xsd_blocks_validation_and_generation_without_official_spec(): void
    {
        Storage::fake('local');
        $user = $this->userWith(['fis.outbound.view','fis.outbound.create','fis.outbound.generate','fis.outbound.validate']);
        $this->withApiAuth($user);
        $package = FisOutboundPackage::create(['package_type' => 'admission', 'schema_version' => 'pending-official-spec', 'environment' => 'test', 'status' => 'draft', 'payload_path' => 'fis/outbound/missing.xml']);

        $this->postJson("/api/fis/outbound/packages/{$package->id}/generate")
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'Official FIS schema is not loaded. XML generation is blocked to avoid inventing namespaces or formats.']);

        Storage::disk('local')->put($package->payload_path, '<Package/>');
        $this->postJson("/api/fis/outbound/packages/{$package->id}/validate")
            ->assertOk()
            ->assertJsonPath('validation.ok', false)
            ->assertJsonPath('validation.errors.0.code', 'xsd_missing');
    }


    public function test_gateway_transport_uses_redacted_package_hash_and_requires_configuration(): void
    {
        Storage::fake('local');
        $user = $this->userWith(['fis.outbound.view','fis.outbound.create','fis.outbound.send_test','fis.outbound.status']);
        $this->withApiAuth($user);

        $package = FisOutboundPackage::create([
            'package_type' => 'admission',
            'schema_version' => 'pending-official-spec',
            'environment' => 'test',
            'status' => 'validated',
            'created_by' => $user->id,
            'payload_path' => 'fis/outbound/gateway.xml',
        ]);
        Storage::disk('local')->put($package->payload_path, '<Package><Person>Secret Name</Person></Package>');

        config(['fis_api.transport' => 'gateway', 'fis_api.gateway_enabled' => true, 'fis_api.gateway_allowed_environment' => 'test', 'fis_api.gateway_url' => 'http://fis-agent.test', 'fis_api.gateway_shared_secret' => 'test-secret']);
        Http::fake([
            'fis-agent.test/fis/test/import' => Http::response(['ok' => false, 'status' => 'blocked', 'message' => 'send disabled', 'gateway_version' => '0.1.0-dev'], 200),
        ]);

        $this->postJson("/api/fis/outbound/packages/{$package->id}/send", ['mock' => false])
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.package_id', null)
            ->assertJsonPath('data.last_error_message', 'send disabled')
            ->assertJsonPath('data.response_metadata.transport', 'gateway');

        Http::assertSent(function ($request) {
            $data = $request->data();
            return $request->url() === 'http://fis-agent.test/fis/test/import'
                && $request->hasHeader('X-FIS-Signature')
                && $request->hasHeader('X-FIS-Timestamp')
                && $request->hasHeader('X-FIS-Nonce')
                && $request->hasHeader('X-FIS-Body-SHA256')
                && isset($data['package_hash'])
                && $data['payload'] === base64_encode('<Package><Person>Secret Name</Person></Package>')
                && ! str_contains(json_encode($data), 'Secret Name');
        });

        config(['fis_api.gateway_url' => null, 'fis_api.gateway_shared_secret' => null]);
        $this->postJson("/api/fis/outbound/packages/{$package->id}/send", ['mock' => false])
            ->assertStatus(500);
    }


    public function test_gateway_diagnostics_are_signed_and_disabled_when_feature_flag_is_off(): void
    {
        $user = $this->userWith(['fis.outbound.view','manage_dictionaries']);
        $this->withApiAuth($user);

        config(['fis_api.gateway_enabled' => false, 'fis_api.gateway_url' => 'http://fis-agent.test', 'fis_api.gateway_shared_secret' => 'test-secret']);
        $this->postJson('/api/fis/outbound/gateway/zkspd-check')
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'FIS Gateway is disabled. Set FIS_GATEWAY_ENABLED=true for TEST diagnostics.']);

        config(['fis_api.gateway_enabled' => true, 'fis_api.gateway_allowed_environment' => 'test']);
        Http::fake([
            'fis-agent.test/health' => Http::response(['ok' => true, 'message' => 'healthy'], 200),
            'fis-agent.test/zkspd/check' => Http::response(['ok' => false, 'error_code' => 'zkspd_unreachable', 'message' => 'timeout', 'latency_ms' => 5000], 200),
            'fis-agent.test/fis/test/dictionaries/list' => Http::response(['ok' => true, 'message' => 'soap_ok', 'gateway_version' => '0.1.0-dev'], 200),
        ]);

        $this->getJson('/api/fis/outbound/gateway/health')
            ->assertOk()
            ->assertJsonPath('data.ok', true);

        $this->postJson('/api/fis/outbound/gateway/zkspd-check')
            ->assertOk()
            ->assertJsonPath('data.error_code', 'zkspd_unreachable');

        $this->postJson('/api/fis/outbound/gateway/dictionaries/list')
            ->assertOk()
            ->assertJsonPath('data.gateway_version', '0.1.0-dev');

        Http::assertSent(function ($request) {
            return $request->url() === 'http://fis-agent.test/zkspd/check'
                && $request->hasHeader('X-FIS-Signature')
                && $request->hasHeader('X-FIS-Body-SHA256');
        });
    }

    public function test_production_is_blocked_and_permissions_are_required(): void
    {
        $viewer = $this->userWith(['fis.outbound.view']);
        $this->withApiAuth($viewer);
        $this->postJson('/api/fis/outbound/packages', ['package_type' => 'admission', 'environment' => 'test'])->assertForbidden();

        $creator = $this->userWith(['fis.outbound.view','fis.outbound.create']);
        $this->withApiAuth($creator);
        $this->postJson('/api/fis/outbound/packages', ['package_type' => 'admission', 'environment' => 'production'])->assertForbidden();
        $this->postJson('/api/fis/outbound/packages', ['package_type' => 'admission', 'environment' => 'test'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Test Role '.substr(md5(json_encode($permissions)), 0, 8), 'code' => 'test_'.md5(json_encode($permissions)), 'description' => 'Test role']);
        foreach ($permissions as $code) {
            $permission = Permission::firstOrCreate(['code' => $code], ['name' => $code, 'module' => 'Test', 'description' => $code, 'system' => true, 'active' => true]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->attach($role->id);
        return $user;
    }
}
