<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\DigitalIdentity;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccessGateApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_scan_active_qr_allows_entry_and_next_scan_exit_after_interval(): void
    {
        $identity = $this->createStudentIdentity();

        $this->postJson('/api/access/scan', [
            'token' => $identity->token,
            'access_point' => 'Главный вход',
            'device_name' => 'USB QR Scanner',
        ])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_ALLOWED)
            ->assertJsonPath('data.direction', AccessEvent::DIRECTION_IN)
            ->assertJsonPath('data.owner.last_name', 'Иванов');

        $this->travel(3)->seconds();

        $this->postJson('/api/access/scan', ['token' => $identity->token])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_ALLOWED)
            ->assertJsonPath('data.direction', AccessEvent::DIRECTION_OUT);
    }

    public function test_fast_duplicate_scan_is_ignored(): void
    {
        $identity = $this->createStudentIdentity();

        $firstId = $this->postJson('/api/access/scan', ['token' => $identity->token])
            ->assertOk()
            ->assertJsonPath('data.direction', AccessEvent::DIRECTION_IN)
            ->json('data.id');

        $this->postJson('/api/access/scan', ['token' => $identity->token])
            ->assertOk()
            ->assertJsonPath('data.id', $firstId)
            ->assertJsonPath('data.direction', AccessEvent::DIRECTION_IN)
            ->assertJsonPath('data.duplicate_ignored', true);

        $this->assertSame(1, AccessEvent::count());
    }

    public function test_scan_revoked_qr_creates_denied_event(): void
    {
        $identity = $this->createTeacherIdentity([
            'status' => DigitalIdentity::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);

        $this->postJson('/api/access/scan', ['token' => $identity->token])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_DENIED)
            ->assertJsonPath('data.reason', 'Пропуск отозван.')
            ->assertJsonPath('data.owner.last_name', 'Смирнова');
    }

    public function test_scan_unknown_token_creates_denied_event_without_owner(): void
    {
        $this->postJson('/api/access/scan', ['token' => 'unknown-token'])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_DENIED)
            ->assertJsonPath('data.reason', 'Пропуск не найден.')
            ->assertJsonPath('data.owner', null);

        $this->assertDatabaseHas('access_events', [
            'digital_identity_id' => null,
            'result' => AccessEvent::RESULT_DENIED,
            'reason' => 'Пропуск не найден.',
        ]);
    }

    public function test_it_lists_access_events(): void
    {
        $identity = $this->createStudentIdentity();
        $this->postJson('/api/access/scan', ['token' => $identity->token])->assertOk();

        $this->getJson('/api/access/events')
            ->assertOk()
            ->assertJsonPath('data.0.result', AccessEvent::RESULT_ALLOWED)
            ->assertJsonPath('data.0.owner.last_name', 'Иванов');
    }



    public function test_scan_accepts_cp1_prefix_and_trimmed_hid_suffixes(): void
    {
        $identity = $this->createStudentIdentity();

        $this->postJson('/api/access/scan', [
            'token' => " CP1:{$identity->token}\r\n",
            'access_point' => 'Главный вход',
            'device_name' => 'USB QR Scanner',
        ])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_ALLOWED)
            ->assertJsonPath('data.direction', AccessEvent::DIRECTION_IN)
            ->assertJsonPath('data.owner.last_name', 'Иванов');

        $this->travel(3)->seconds();

        $this->postJson('/api/access/scan', ['token' => "\t{$identity->token}\n"])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_ALLOWED)
            ->assertJsonPath('data.direction', AccessEvent::DIRECTION_OUT);
    }



    public function test_access_scan_permissions_allow_security_and_block_teacher_student(): void
    {
        $identity = $this->createStudentIdentity();
        $permission = Permission::query()->firstOrCreate(['code' => 'manage_dictionaries'], ['name' => 'Управление справочниками']);
        Role::query()->firstOrCreate(['code' => 'security'], ['name' => 'Сотрудник проходной'])->permissions()->sync([$permission->id]);
        $security = $this->createApiUser(roleCode: 'security');
        $teacher = $this->createApiUser(roleCode: 'teacher');
        $student = $this->createApiUser(roleCode: 'student');

        $this->withApiAuth($security)
            ->postJson('/api/access/scan', ['token' => $identity->token])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_ALLOWED);

        $this->withApiAuth($teacher)
            ->postJson('/api/access/scan', ['token' => $identity->token])
            ->assertForbidden();

        $this->withApiAuth($student)
            ->postJson('/api/access/scan', ['token' => $identity->token])
            ->assertForbidden();
    }

    private function createStudentIdentity(array $overrides = []): DigitalIdentity
    {
        $group = Group::create([
            'name' => 'ИСП-101',
            'specialty' => 'Инструментальное исполнительство',
            'course' => 1,
            'year_start' => 2026,
        ]);

        $student = Student::create([
            'group_id' => $group->id,
            'last_name' => 'Иванов',
            'first_name' => 'Дмитрий',
            'status' => 'active',
        ]);

        return DigitalIdentity::create(array_merge([
            'entity_type' => DigitalIdentity::ENTITY_STUDENT,
            'entity_id' => $student->id,
            'token' => (string) Str::uuid(),
            'status' => DigitalIdentity::STATUS_ACTIVE,
            'issued_at' => now(),
        ], $overrides));
    }

    private function createTeacherIdentity(array $overrides = []): DigitalIdentity
    {
        $teacher = Teacher::create([
            'last_name' => 'Смирнова',
            'first_name' => 'Елена',
            'department' => 'Музыкальное отделение',
        ]);

        return DigitalIdentity::create(array_merge([
            'entity_type' => DigitalIdentity::ENTITY_TEACHER,
            'entity_id' => $teacher->id,
            'token' => (string) Str::uuid(),
            'status' => DigitalIdentity::STATUS_ACTIVE,
            'issued_at' => now(),
        ], $overrides));
    }
}
