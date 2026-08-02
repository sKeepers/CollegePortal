<?php

namespace Tests\Feature;

use App\Models\DigitalIdentity;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\QrSvgService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DigitalIdentityApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_it_issues_digital_pass_for_student(): void
    {
        $student = $this->createStudent();

        $response = $this->postJson('/api/digital-identities/issue', [
            'entity_type' => 'student',
            'entity_id' => $student->id,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.entity_type', 'student')
            ->assertJsonPath('data.entity_id', $student->id)
            ->assertJsonPath('data.status', DigitalIdentity::STATUS_ACTIVE)
            ->assertJsonPath('data.owner.last_name', 'Иванов');

        $this->assertTrue(Str::isUuid($response->json('data.token')));
    }

    public function test_it_issues_digital_pass_for_teacher_and_revokes_it(): void
    {
        $teacher = Teacher::create([
            'last_name' => 'Петров',
            'first_name' => 'Алексей',
            'phone' => '+79990000002',
            'email' => 'teacher@example.test',
            'department' => 'Музыкальное отделение',
        ]);

        $identityId = $this->postJson('/api/digital-identities/issue', [
            'entity_type' => 'teacher',
            'entity_id' => $teacher->id,
        ])
            ->assertCreated()
            ->json('data.id');

        $this->postJson("/api/digital-identities/{$identityId}/revoke")
            ->assertOk()
            ->assertJsonPath('data.status', DigitalIdentity::STATUS_REVOKED);

        $this->assertDatabaseHas('digital_identities', [
            'id' => $identityId,
            'status' => DigitalIdentity::STATUS_REVOKED,
        ]);
    }

    public function test_deactivating_or_deleting_owner_revokes_active_pass(): void
    {
        $student = $this->createStudent();
        $teacher = Teacher::create([
            'last_name' => 'Петров',
            'first_name' => 'Алексей',
            'department' => 'Музыкальное отделение',
            'is_active' => true,
        ]);
        $user = User::factory()->create(['is_active' => true]);
        $teacher->forceFill(['user_id' => $user->id])->save();

        $studentPass = DigitalIdentity::create([
            'entity_type' => DigitalIdentity::ENTITY_STUDENT,
            'entity_id' => $student->id,
            'token' => (string) Str::uuid(),
            'status' => DigitalIdentity::STATUS_ACTIVE,
            'issued_at' => now(),
        ]);
        $teacherPass = DigitalIdentity::create([
            'entity_type' => DigitalIdentity::ENTITY_TEACHER,
            'entity_id' => $teacher->id,
            'token' => (string) Str::uuid(),
            'status' => DigitalIdentity::STATUS_ACTIVE,
            'issued_at' => now(),
        ]);

        $student->update(['status' => 'academic_leave']);
        $user->update(['is_active' => false]);

        $this->assertDatabaseHas('digital_identities', ['id' => $studentPass->id, 'status' => DigitalIdentity::STATUS_REVOKED]);
        $this->assertDatabaseHas('digital_identities', ['id' => $teacherPass->id, 'status' => DigitalIdentity::STATUS_REVOKED]);

        $deletedTeacher = Teacher::create([
            'last_name' => 'Соколова',
            'first_name' => 'Анна',
            'department' => 'Музыкальное отделение',
            'is_active' => true,
        ]);
        $deletedTeacherPass = DigitalIdentity::create([
            'entity_type' => DigitalIdentity::ENTITY_TEACHER,
            'entity_id' => $deletedTeacher->id,
            'token' => (string) Str::uuid(),
            'status' => DigitalIdentity::STATUS_ACTIVE,
            'issued_at' => now(),
        ]);

        $deletedTeacher->delete();

        $this->assertDatabaseHas('digital_identities', ['id' => $deletedTeacherPass->id, 'status' => DigitalIdentity::STATUS_REVOKED]);

        $deletedStudent = $this->createStudent(2);
        $deletedStudentPass = DigitalIdentity::create([
            'entity_type' => DigitalIdentity::ENTITY_STUDENT,
            'entity_id' => $deletedStudent->id,
            'token' => (string) Str::uuid(),
            'status' => DigitalIdentity::STATUS_ACTIVE,
            'issued_at' => now(),
        ]);
        $deletedStudent->delete();

        $this->assertDatabaseHas('digital_identities', ['id' => $deletedStudentPass->id, 'status' => DigitalIdentity::STATUS_REVOKED]);
    }

    public function test_qr_svg_does_not_expose_personal_data(): void
    {
        $student = $this->createStudent();

        $identityId = $this->postJson('/api/digital-identities/issue', [
            'entity_type' => 'student',
            'entity_id' => $student->id,
        ])->json('data.id');

        $svg = $this->get("/api/digital-identities/{$identityId}/qr")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->getContent();

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringNotContainsString('Иванов', $svg);
        $this->assertStringNotContainsString('Дмитрий', $svg);
        $this->assertStringNotContainsString('student@example.test', $svg);
        $this->assertStringNotContainsString('+79990000001', $svg);
    }



    public function test_qr_svg_and_png_are_dynamic_payloads_without_personal_data(): void
    {
        $student = $this->createStudent();

        $response = $this->postJson('/api/digital-identities/issue', [
            'entity_type' => 'student',
            'entity_id' => $student->id,
        ])->assertCreated();

        $identityId = $response->json('data.id');
        $token = $response->json('data.token');

        $svg = $this->get("/api/digital-identities/{$identityId}/qr?format=svg")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml; charset=UTF-8')
            ->assertHeader('X-QR-Content', 'dynamic')
            ->getContent();

        $png = $this->get("/api/digital-identities/{$identityId}/qr?format=png")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-QR-Content', 'dynamic')
            ->getContent();

        $this->assertMatchesRegularExpression('/^[\x21-\x7E]+$/', $token);
        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
        $this->assertStringContainsString('shape-rendering="crispEdges"', $svg);

        foreach (['Иванов', 'Дмитрий', 'student@example.test', '+79990000001'] as $personalData) {
            $this->assertStringNotContainsString($personalData, $svg);
            $this->assertStringNotContainsString($personalData, $png);
        }
    }

    public function test_qr_service_renders_readable_sized_png_and_crisp_svg_for_synthetic_token(): void
    {
        $token = 'CP2:ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $service = app(QrSvgService::class);

        $svg = $service->renderSvg($token);
        $png = $service->renderPng($token);
        $image = getimagesizefromstring($png);

        $this->assertIsArray($image);
        $this->assertGreaterThanOrEqual(360, $image[0]);
        $this->assertSame($image[0], $image[1]);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('shape-rendering="crispEdges"', $svg);
        $this->assertStringNotContainsString('Иванов', $svg);
        $this->assertStringNotContainsString('student@example.test', $svg);
        $this->assertStringStartsWith("\x89PNG", $png);
    }

    public function test_teacher_can_view_and_download_only_own_digital_pass(): void
    {
        $teacher = Teacher::create([
            'last_name' => 'Смирнова',
            'first_name' => 'Елена',
            'department' => 'Музыкальное отделение',
        ]);
        $otherTeacher = Teacher::create([
            'last_name' => 'Петров',
            'first_name' => 'Алексей',
            'department' => 'Музыкальное отделение',
        ]);
        $user = $this->userWithPermissions('teacher', ['view_own_data']);
        $teacher->forceFill(['user_id' => $user->id])->save();

        $ownIdentity = DigitalIdentity::create([
            'entity_type' => DigitalIdentity::ENTITY_TEACHER,
            'entity_id' => $teacher->id,
            'token' => (string) Str::uuid(),
            'status' => DigitalIdentity::STATUS_ACTIVE,
            'issued_at' => now(),
        ]);
        $otherIdentity = DigitalIdentity::create([
            'entity_type' => DigitalIdentity::ENTITY_TEACHER,
            'entity_id' => $otherTeacher->id,
            'token' => (string) Str::uuid(),
            'status' => DigitalIdentity::STATUS_ACTIVE,
            'issued_at' => now(),
        ]);

        $this->withApiAuth($user)
            ->getJson('/api/digital-identities?mine=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownIdentity->id)
            ->assertJsonPath('data.0.token', null);

        $this->get("/api/digital-identities/{$ownIdentity->id}/qr")
            ->assertOk()
            ->assertHeader('X-QR-Content', 'dynamic');

        $this->get("/api/digital-identities/{$otherIdentity->id}/qr")
            ->assertForbidden();
    }

    public function test_teacher_without_manage_permission_does_not_receive_full_registry(): void
    {
        $teacher = Teacher::create([
            'last_name' => 'Смирнова',
            'first_name' => 'Елена',
            'department' => 'Музыкальное отделение',
        ]);
        $otherTeacher = Teacher::create([
            'last_name' => 'Петров',
            'first_name' => 'Алексей',
            'department' => 'Музыкальное отделение',
        ]);
        $user = $this->userWithPermissions('teacher', ['view_own_data']);
        $teacher->forceFill(['user_id' => $user->id])->save();

        $ownIdentity = DigitalIdentity::create([
            'entity_type' => DigitalIdentity::ENTITY_TEACHER,
            'entity_id' => $teacher->id,
            'token' => (string) Str::uuid(),
            'status' => DigitalIdentity::STATUS_ACTIVE,
            'issued_at' => now(),
        ]);
        DigitalIdentity::create([
            'entity_type' => DigitalIdentity::ENTITY_TEACHER,
            'entity_id' => $otherTeacher->id,
            'token' => (string) Str::uuid(),
            'status' => DigitalIdentity::STATUS_ACTIVE,
            'issued_at' => now(),
        ]);

        $this->withApiAuth($user)
            ->getJson('/api/digital-identities')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownIdentity->id);
    }

    private function createStudent(int $number = 1): Student
    {
        $group = Group::create([
            'name' => "ИСП-10{$number}",
            'specialty' => 'Инструментальное исполнительство',
            'course' => 1,
            'year_start' => 2026,
        ]);

        return Student::create([
            'group_id' => $group->id,
            'last_name' => 'Иванов',
            'first_name' => 'Дмитрий',
            'phone' => '+79990000001',
            'email' => $number === 1 ? 'student@example.test' : "student{$number}@example.test",
            'status' => 'active',
        ]);
    }

    private function userWithPermissions(string $roleCode, array $permissionCodes): User
    {
        $role = Role::query()->firstOrCreate(['code' => $roleCode], ['name' => $roleCode]);
        $permissionIds = collect($permissionCodes)->map(function (string $code): int {
            return Permission::query()->firstOrCreate(['code' => $code], ['name' => $code])->id;
        });
        $role->permissions()->sync($permissionIds);

        return $this->createApiUser(roleCode: $roleCode);
    }
}
