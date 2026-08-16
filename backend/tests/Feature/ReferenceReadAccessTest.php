<?php

namespace Tests\Feature;

use App\Models\EducationProgram;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Чтение специальностей и образовательных программ.
 *
 * Роль, которая ведёт группы и выпуск, получала `403` на обоих реестрах:
 * префикс целиком требовал права на управление справочниками. Читать их
 * должны все, кто ими пользуется; править — по-прежнему владелец справочников.
 */
class ReferenceReadAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_study_records_reads_specialties_and_programs(): void
    {
        $this->seed(RoleSeeder::class);
        $this->createProgram();
        $this->withApiAuth($this->userWithRole('study_records'));

        $this->getJson('/api/specialties')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/education-programs')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_every_seeded_role_reads_both_registers(): void
    {
        $this->seed(RoleSeeder::class);
        $this->createProgram();

        foreach (Role::query()->pluck('code') as $code) {
            $this->withApiAuth($this->userWithRole($code));

            $this->getJson('/api/specialties')->assertOk();
            $this->getJson('/api/education-programs')->assertOk();
        }
    }

    /**
     * Обратная сторона правила «таблица только сужает доступ»: перевод чтения
     * на `reference.view` не должен отнять его у того, кому выдали правку.
     */
    public function test_the_right_to_change_covers_the_right_to_read(): void
    {
        $this->createProgram();
        $this->withApiAuth($this->userWith(['reference.manage']));

        $this->getJson('/api/specialties')->assertOk();
        $this->getJson('/api/education-programs')->assertOk();
        $this->getJson('/api/admin/reference/items')->assertOk();
    }

    public function test_changing_the_registers_still_needs_the_manage_permission(): void
    {
        $program = $this->createProgram();
        $this->withApiAuth($this->userWith(['reference.view']));

        $this->postJson('/api/specialties', ['code' => '53.02.09', 'name' => 'Новая'])->assertForbidden();
        $this->putJson("/api/education-programs/{$program->id}", ['name' => 'Переименовано'])->assertForbidden();
        $this->deleteJson("/api/education-programs/{$program->id}")->assertForbidden();

        $this->assertSame('Инструментальное исполнительство', $program->fresh()->name);
    }

    public function test_a_role_without_any_reference_right_still_gets_nothing(): void
    {
        $this->createProgram();
        $this->withApiAuth($this->userWith(['students.view']));

        $this->getJson('/api/specialties')->assertForbidden();
        $this->getJson('/api/education-programs')->assertForbidden();
    }

    private function createProgram(): EducationProgram
    {
        $specialty = Specialty::query()->create(['code' => '53.02.03', 'name' => 'Инструментальное исполнительство']);

        return EducationProgram::query()->create([
            'specialty_id' => $specialty->id,
            'name' => 'Инструментальное исполнительство',
            'year_start' => 2026,
            'study_form' => 'Очная',
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('code', $roleCode)->value('id'));

        return $user;
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['code' => 'read_'.substr(md5(json_encode($permissions)), 0, 12)], ['name' => 'Чтение '.substr(md5(json_encode($permissions)), 0, 8), 'description' => 'Test role']);

        foreach ($permissions as $code) {
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                ['name' => $code, 'module' => 'Test', 'description' => $code, 'system' => true, 'active' => true],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->roles()->attach($role->id);

        return $user;
    }
}
