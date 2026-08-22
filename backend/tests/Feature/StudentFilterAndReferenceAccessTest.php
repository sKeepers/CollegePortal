<?php

namespace Tests\Feature;

use App\Models\EducationProgram;
use App\Models\Group;
use App\Models\Permission;
use App\Models\ReferenceCatalog;
use App\Models\ReferenceItem;
use App\Models\Role;
use App\Models\Specialty;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Фильтры реестра студентов и доступ к справочникам.
 *
 * `BUG-010`: у роли, ведущей контингент, выпадающий список статусов был пуст —
 * справочники требовали права на управление ими. `GUI-018`: курс и
 * специальность нельзя было выбрать вовсе.
 */
class StudentFilterAndReferenceAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_students_are_filtered_by_course_and_by_specialty(): void
    {
        $this->withApiAuth();

        $piano = $this->createGroup('ФП-11', course: 1, specialtyCode: '53.02.03');
        $vocal = $this->createGroup('ВК-21', course: 2, specialtyCode: '53.02.04');

        $this->createStudent($piano, 'Иванов');
        $this->createStudent($vocal, 'Соколова');

        $this->getJson('/api/students?course=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.last_name', 'Иванов');

        $specialtyId = $vocal->educationProgram->specialty_id;

        $this->getJson("/api/students?specialty_id={$specialtyId}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.last_name', 'Соколова');

        // Курс и специальность из разных групп вместе не дают никого: фильтры
        // сужают выборку, а не складываются.
        $this->getJson("/api/students?course=1&specialty_id={$specialtyId}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_group_list_can_be_asked_for_more_than_one_page(): void
    {
        $this->withApiAuth();

        for ($index = 1; $index <= 25; $index++) {
            $this->createGroup('Г-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT), course: 1, specialtyCode: '53.02.'.str_pad((string) $index, 2, '0', STR_PAD_LEFT));
        }

        $this->getJson('/api/groups')->assertOk()->assertJsonCount(20, 'data');
        $this->getJson('/api/groups?per_page=100')->assertOk()->assertJsonCount(25, 'data');
    }

    public function test_reading_reference_items_needs_only_the_view_permission(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(ReferenceDataSeeder::class);
        $catalog = ReferenceCatalog::query()->where('code', 'student_statuses')->firstOrFail();

        // Роль ведёт контингент, но справочниками не управляет — ровно тот
        // случай, в котором список статусов молчал.
        $this->withApiAuth($this->userWith(['students.view', 'reference.view']));
        $this->getJson('/api/admin/reference/items?catalog_code=student_statuses')
            ->assertOk()
            ->assertJsonPath('data.0.catalog_id', $catalog->id);

        $this->withApiAuth($this->userWith(['students.view']));
        $this->getJson('/api/admin/reference/items?catalog_code=student_statuses')
            ->assertForbidden();
    }

    public function test_changing_a_reference_item_still_needs_the_manage_permission(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(ReferenceDataSeeder::class);
        $catalog = ReferenceCatalog::query()->where('code', 'student_statuses')->firstOrFail();
        $item = ReferenceItem::query()->where('catalog_id', $catalog->id)->firstOrFail();

        $this->withApiAuth($this->userWith(['students.view', 'reference.view']));
        $this->putJson("/api/admin/reference/items/{$item->id}", ['name' => 'Переименовано'])
            ->assertForbidden();

        $this->assertSame($item->name, $item->fresh()->name);
    }

    public function test_every_seeded_role_can_read_reference_items(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(ReferenceDataSeeder::class);

        foreach (Role::query()->pluck('code') as $code) {
            $user = User::factory()->create();
            $user->roles()->attach(Role::query()->where('code', $code)->value('id'));
            $this->withApiAuth($user);

            $this->getJson('/api/admin/reference/items?catalog_code=student_statuses')
                ->assertOk();
        }
    }

    private function createGroup(string $name, int $course, string $specialtyCode): Group
    {
        $specialty = Specialty::query()->create(['code' => $specialtyCode, 'name' => 'Специальность '.$specialtyCode]);
        $program = EducationProgram::query()->create([
            'specialty_id' => $specialty->id,
            'name' => 'Программа '.$specialtyCode,
            'year_start' => 2026,
            'study_form' => 'Очная',
        ]);

        return Group::query()->create([
            'name' => $name,
            'specialty' => $specialty->name,
            'education_program_id' => $program->id,
            // Курс считается из года набора — задаём год, отвечающий курсу.
            'year_start' => Group::academicYear() - $course + 1,
        ]);
    }

    private function createStudent(Group $group, string $lastName): Student
    {
        return Student::query()->create([
            'group_id' => $group->id,
            'last_name' => $lastName,
            'first_name' => 'Проверочный',
            'status' => 'active',
        ]);
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['code' => 'filters_'.substr(md5(json_encode($permissions)), 0, 12)], ['name' => 'Фильтры '.substr(md5(json_encode($permissions)), 0, 8), 'description' => 'Test role']);

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
