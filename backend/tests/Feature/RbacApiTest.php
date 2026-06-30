<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
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

    private function createRoleWithPermissions(string $roleCode, array $permissionCodes): Role
    {
        $role = Role::create([
            'name' => str($roleCode)->replace('_', ' ')->title()->toString(),
            'code' => $roleCode,
        ]);

        $permissionIds = collect($permissionCodes)
            ->map(fn (string $code) => Permission::create(['name' => $code, 'code' => $code])->id);

        $role->permissions()->sync($permissionIds);

        return $role;
    }
}
