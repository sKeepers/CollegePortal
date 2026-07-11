<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_cannot_manage_dictionaries(): void
    {
        $studentRole = $this->createRoleWithPermissions('student', ['view_own_data']);
        $user = $this->createApiUser(roleCode: $studentRole->code);

        $this->withApiAuth($user)
            ->getJson('/api/groups')
            ->assertForbidden();
    }

    public function test_teacher_can_manage_journal_but_not_dictionaries(): void
    {
        $teacherRole = $this->createRoleWithPermissions('teacher', ['manage_journal', 'view_own_data']);
        $user = $this->createApiUser(roleCode: $teacherRole->code);

        $this->withApiAuth($user)
            ->getJson('/api/attendance')
            ->assertOk();

        $this->withApiAuth($user)
            ->getJson('/api/groups')
            ->assertForbidden();
    }

    public function test_academic_office_can_manage_dictionaries_and_schedule(): void
    {
        $role = $this->createRoleWithPermissions('academic_office', ['manage_dictionaries', 'manage_schedule']);
        $user = $this->createApiUser(roleCode: $role->code);

        $this->withApiAuth($user)
            ->getJson('/api/groups')
            ->assertOk();

        $this->withApiAuth($user)
            ->getJson('/api/schedule-lessons')
            ->assertOk();
    }

    public function test_admin_bypasses_permissions(): void
    {
        $admin = $this->createApiUser(roleCode: 'admin');

        $this->withApiAuth($admin)
            ->getJson('/api/groups')
            ->assertOk();
    }


    public function test_permission_matrix_blocks_forbidden_role_access(): void
    {
        $this->seed(RoleSeeder::class);

        $teacher = $this->createApiUser(roleCode: 'teacher');
        $student = $this->createApiUser(roleCode: 'student');
        $security = $this->createApiUser(roleCode: 'security');

        $this->withApiAuth($teacher)->getJson('/api/students')->assertForbidden();
        $this->withApiAuth($student)->getJson('/api/frdo-packages')->assertForbidden();
        $this->withApiAuth($security)->getJson('/api/attendance')->assertForbidden();
    }

    public function test_director_can_view_but_cannot_mutate_academic_data(): void
    {
        $this->seed(RoleSeeder::class);
        $director = $this->createApiUser(roleCode: 'director');

        $this->withApiAuth($director)->getJson('/api/students')->assertOk();
        $this->withApiAuth($director)->postJson('/api/students', [
            'last_name' => 'Тест',
            'first_name' => 'Директор',
            'status' => 'active',
        ])->assertForbidden();
    }

    public function test_explicit_permission_allows_api_access(): void
    {
        $role = $this->createRoleWithPermissions('students_viewer', ['students.view']);
        $user = $this->createApiUser(roleCode: $role->code);

        $this->withApiAuth($user)->getJson('/api/students')->assertOk();
        $this->withApiAuth($user)->postJson('/api/students', [
            'last_name' => 'Нет',
            'first_name' => 'Права',
            'status' => 'active',
        ])->assertForbidden();
    }

    public function test_permissions_api_requires_permissions_manage(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->createApiUser(roleCode: 'admin');
        $director = $this->createApiUser(roleCode: 'director');

        $this->withApiAuth($admin)->getJson('/api/admin/permissions')->assertOk();
        $this->withApiAuth($director)->getJson('/api/admin/permissions')->assertForbidden();
    }

    private function createRoleWithPermissions(string $roleCode, array $permissionCodes): Role
    {
        $role = Role::create([
            'name' => str($roleCode)->replace('_', ' ')->title()->toString(),
            'code' => $roleCode,
        ]);

        $permissionIds = collect($permissionCodes)
            ->map(fn (string $code) => Permission::create(['name' => $code, 'code' => $code, 'module' => 'Test', 'system' => false, 'active' => true])->id);

        $role->permissions()->sync($permissionIds);

        return $role;
    }
}
