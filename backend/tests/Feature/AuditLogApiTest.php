<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_service_writes_sanitized_log(): void
    {
        $user = $this->createApiUser();

        AuditLogService::log(
            'users',
            'create',
            $user,
            ['password' => 'secret', 'name' => 'Old'],
            ['api_token_hash' => 'hidden', 'name' => 'New'],
            null,
            $user,
        );

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'users',
            'action' => 'create',
            'entity_type' => 'User',
            'entity_id' => $user->id,
            'user_id' => $user->id,
        ]);

        $log = AuditLog::firstOrFail();
        $this->assertArrayNotHasKey('password', $log->old_values);
        $this->assertArrayNotHasKey('api_token_hash', $log->new_values);
    }

    public function test_audit_service_redacts_contact_and_identity_values(): void
    {
        $user = $this->createApiUser();

        AuditLogService::log(
            'identity',
            'update',
            ['type' => 'Person', 'id' => 10],
            ['email' => 'old@example.test', 'phone' => '+7 900 111-22-33', 'name' => 'Old'],
            [
                'email' => 'new@example.test',
                'phone' => '+7 900 444-55-66',
                'address' => 'Тестовый адрес',
                'identity_document' => ['series' => '1234', 'number' => '567890'],
                'name' => 'New',
            ],
            null,
            $user,
        );

        $log = AuditLog::firstOrFail();

        $this->assertSame('[redacted]', $log->old_values['email']);
        $this->assertSame('[redacted]', $log->old_values['phone']);
        $this->assertSame('Old', $log->old_values['name']);
        $this->assertSame('[redacted]', $log->new_values['email']);
        $this->assertSame('[redacted]', $log->new_values['phone']);
        $this->assertSame('[redacted]', $log->new_values['address']);
        $this->assertSame('[redacted]', $log->new_values['identity_document']);
        $this->assertSame('New', $log->new_values['name']);
        $this->assertStringNotContainsString('new@example.test', json_encode($log->new_values));
        $this->assertStringNotContainsString('9004445566', json_encode($log->new_values));
    }

    public function test_admin_can_list_and_show_audit_logs(): void
    {
        $user = $this->createApiUser();
        $this->withApiAuth($user);

        AuditLogService::log('roles', 'update', ['type' => 'Role', 'id' => 5], ['name' => 'Old'], ['name' => 'New'], null, $user);

        $id = $this->getJson('/api/admin/audit?module=roles&action=update')
            ->assertOk()
            ->assertJsonPath('data.0.module', 'roles')
            ->assertJsonPath('data.0.action', 'update')
            ->json('data.0.id');

        $this->getJson("/api/admin/audit/{$id}")
            ->assertOk()
            ->assertJsonPath('data.old_values.name', 'Old')
            ->assertJsonPath('data.new_values.name', 'New');
    }

    public function test_user_actions_are_logged(): void
    {
        $role = Role::firstOrCreate(['code' => 'teacher'], ['name' => 'Преподаватель']);
        $this->withApiAuth();

        $this->postJson('/api/admin/users', [
            'name' => 'Аудит Пользователь',
            'email' => 'audit.user@example.test',
            'password' => 'Demo12345',
            'role_id' => $role->id,
            'is_active' => true,
        ])->assertCreated();

        $created = User::where('email', 'audit.user@example.test')->firstOrFail();

        $this->postJson("/api/admin/users/{$created->id}/block")
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', ['module' => 'users', 'action' => 'create', 'entity_id' => $created->id]);
        $this->assertDatabaseHas('audit_logs', ['module' => 'users', 'action' => 'block', 'entity_id' => $created->id]);
    }
}
