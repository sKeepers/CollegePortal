<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\AccessPassToken;
use App\Models\AuditLog;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccessControlFoundationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dynamic_token_issue_uses_short_ttl_and_contains_no_personal_data(): void
    {
        $user = $this->createOperator(['access.manage']);
        $person = $this->createStudentPerson();

        $response = $this->withApiAuth($user)
            ->postJson('/api/access/token/issue', ['person_id' => $person->id])
            ->assertOk()
            ->assertJsonPath('data.ttl_seconds', 30);

        $token = $response->json('data.token');
        $this->assertStringStartsWith('CP2:', $token);
        $this->assertStringNotContainsString('Иванов', $token);
        $this->assertStringNotContainsString('Дмитрий', $token);
        $this->assertStringNotContainsString('student@example.test', $token);
        $this->assertStringContainsString('<svg', $response->json('data.qr_svg'));

        $this->assertDatabaseHas('access_pass_tokens', ['person_id' => $person->id]);
        $this->assertDatabaseMissing('access_pass_tokens', ['token_hash' => $token]);
        $this->assertSame(64, strlen(AccessPassToken::first()->token_hash));
    }

    public function test_dynamic_token_allows_once_and_rejects_replay(): void
    {
        $operator = $this->createOperator(['access.scan', 'access.view']);
        $person = $this->createStudentPerson();
        $token = $this->withApiAuth($this->createOperator(['access.manage']))
            ->postJson('/api/access/token/issue', ['person_id' => $person->id])
            ->json('data.token');

        $this->withApiAuth($operator)
            ->postJson('/api/access/scan', ['token' => $token, 'access_point' => 'Главный вход'])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_ALLOWED)
            ->assertJsonPath('data.direction', AccessEvent::DIRECTION_ENTRY)
            ->assertJsonPath('data.owner.display_name', 'Иванов Дмитрий');

        $this->withApiAuth($operator)
            ->postJson('/api/access/scan', ['token' => $token, 'access_point' => 'Главный вход'])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_DENIED)
            ->assertJsonPath('data.reason_code', 'replayed_token');
    }

    public function test_dynamic_token_expires_after_ttl(): void
    {
        $operator = $this->createOperator(['access.scan']);
        $person = $this->createStudentPerson();
        $token = $this->withApiAuth($this->createOperator(['access.manage']))
            ->postJson('/api/access/token/issue', ['person_id' => $person->id])
            ->json('data.token');

        $this->travel(37)->seconds();

        $this->withApiAuth($operator)
            ->postJson('/api/access/scan', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_DENIED)
            ->assertJsonPath('data.reason_code', 'expired_token');
    }

    public function test_tampered_signature_is_denied_without_raw_token_storage(): void
    {
        $operator = $this->createOperator(['access.scan']);
        $person = $this->createStudentPerson();
        $token = $this->withApiAuth($this->createOperator(['access.manage']))
            ->postJson('/api/access/token/issue', ['person_id' => $person->id])
            ->json('data.token');
        $tampered = substr($token, 0, -2).'xx';

        $this->withApiAuth($operator)
            ->postJson('/api/access/scan', ['token' => $tampered])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_DENIED)
            ->assertJsonPath('data.reason_code', 'invalid_signature');

        $this->assertDatabaseMissing('audit_logs', ['new_values' => $tampered]);
    }

    public function test_entry_exit_sequence_and_duplicate_direction_protection(): void
    {
        $operator = $this->createOperator(['access.scan']);
        $manager = $this->createOperator(['access.manage']);
        $person = $this->createStudentPerson();

        $first = $this->withApiAuth($manager)->postJson('/api/access/token/issue', ['person_id' => $person->id])->json('data.token');
        $this->withApiAuth($operator)
            ->postJson('/api/access/scan', ['token' => $first, 'direction' => 'entry'])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_ALLOWED)
            ->assertJsonPath('data.direction', AccessEvent::DIRECTION_ENTRY);

        $second = $this->withApiAuth($manager)->postJson('/api/access/token/issue', ['person_id' => $person->id])->json('data.token');
        $this->withApiAuth($operator)
            ->postJson('/api/access/scan', ['token' => $second, 'direction' => 'entry'])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_DENIED)
            ->assertJsonPath('data.reason_code', 'duplicate_direction');

        $third = $this->withApiAuth($manager)->postJson('/api/access/token/issue', ['person_id' => $person->id])->json('data.token');
        $this->withApiAuth($operator)
            ->postJson('/api/access/scan', ['token' => $third])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_ALLOWED)
            ->assertJsonPath('data.direction', AccessEvent::DIRECTION_EXIT);
    }

    public function test_rbac_allows_security_operator_and_blocks_teacher(): void
    {
        $person = $this->createStudentPerson();
        $token = $this->withApiAuth($this->createOperator(['access.manage']))
            ->postJson('/api/access/token/issue', ['person_id' => $person->id])
            ->json('data.token');

        $this->withApiAuth($this->createOperator(['access.scan']))
            ->postJson('/api/access/scan', ['token' => $token])
            ->assertOk();

        $fresh = $this->withApiAuth($this->createOperator(['access.manage']))
            ->postJson('/api/access/token/issue', ['person_id' => $person->id])
            ->json('data.token');

        $teacher = $this->createApiUser(roleCode: 'teacher');
        $this->withApiAuth($teacher)
            ->postJson('/api/access/scan', ['token' => $fresh])
            ->assertForbidden();
    }

    private function createStudentPerson(): Person
    {
        $person = Person::create([
            'last_name' => 'Иванов',
            'first_name' => 'Дмитрий',
            'email' => 'student@example.test',
            'status' => 'active',
        ]);
        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]);
        Student::create(['person_id' => $person->id, 'group_id' => $group->id, 'last_name' => 'Иванов', 'first_name' => 'Дмитрий', 'email' => 'student@example.test', 'status' => 'active']);

        return $person->fresh(['primaryStudent.group']);
    }

    /** @param list<string> $permissions */
    private function createOperator(array $permissions): User
    {
        $role = Role::query()->firstOrCreate(['code' => 'test_'.md5(implode(',', $permissions))], ['name' => 'Test role']);
        $ids = collect($permissions)->map(fn (string $code) => Permission::query()->firstOrCreate(['code' => $code], ['name' => $code, 'module' => 'Access Control', 'active' => true, 'system' => true])->id);
        $role->permissions()->sync($ids);

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true, 'api_token_hash' => Hash::make('unused')]);
    }
}
