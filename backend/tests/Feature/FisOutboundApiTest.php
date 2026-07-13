<?php

namespace Tests\Feature;

use App\Models\FisOutboundPackage;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
