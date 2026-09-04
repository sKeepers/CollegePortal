<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Group;
use App\Models\Person;
use App\Models\Role;
use Database\Seeders\RoleSeeder;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Файл со всем контингентом открывается не тем правом, что список на экране.
 *
 * Замер 03.09.2026 17:38 UTC: `GET students/export` отдаёт **618 строк** с
 * персональными данными и до этой правки открывался правом `students.view` —
 * его держат десять ролей. Выгрузку же **выбранных** строк открывает
 * `students.bulk_export`, а он есть у четырёх. Весь контингент одним файлом
 * скачивало больше ролей, чем выборку. Решение владельца 03.09.2026: закрыть
 * тем же правом, что и выборку.
 *
 * Проверка стоит **на обе стороны**, и вторая тут дороже первой: самая
 * вероятная ошибка такой правки — сузить заодно и видимость, а на стенде этого
 * никто не заметит, потому что смотрят администратором. Поэтому рядом с
 * «файл не отдаётся» стоит «список остался целым».
 */
class StudentExportNeedsMoreThanViewingTest extends TestCase
{
    use RefreshDatabase;

    private function seedStudents(int $count = 3): void
    {
        $group = Group::create([
            'name' => 'ПР-11',
            'specialty' => 'Инструментальное исполнительство',
            'course' => 1,
            'year_start' => 2026,
        ]);

        for ($i = 1; $i <= $count; $i++) {
            $person = Person::create(['last_name' => 'Списочный'.$i, 'first_name' => 'Студент', 'status' => 'active']);
            Student::create([
                'last_name' => 'Списочный'.$i,
                'first_name' => 'Студент',
                'person_id' => $person->id,
                'group_id' => $group->id,
                'status' => 'active',
            ]);
        }
    }

    /** @param list<string> $codes */
    private function userWith(array $codes, string $roleCode): User
    {
        // Каталог прав портала заводится сидером при установке: миграции держат
        // только то, что появилось после неё (замер 03.09.2026 — 64 права из
        // 194). Без посева отбор ниже нашёл бы пустоту, и роль осталась бы
        // без прав — проверка была бы не о том.
        $this->seed(RoleSeeder::class);

        $role = Role::query()->firstOrCreate(['code' => $roleCode], ['name' => $roleCode, 'description' => null]);
        $ids = Permission::query()->whereIn('code', $codes)->pluck('id');

        // Код, которого нет в каталоге, молча не выдаётся — и проверка мерила бы
        // пустоту, оставаясь зелёной. Так уже терялись права в сидере.
        $this->assertCount(count($codes), $ids, 'права нет в каталоге: '.implode(', ', $codes));

        $role->permissions()->sync($ids->all());
        $user = $this->createApiUser(roleCode: $roleCode);
        $user->forceFill(['role_id' => $role->id])->save();
        $user->roles()->sync([$role->id => ['is_primary' => true]]);

        return $user;
    }

    public function test_a_role_that_only_views_students_does_not_take_the_whole_file(): void
    {
        $this->seedStudents();
        $viewer = $this->userWith(['students.view'], 'curator');

        $this->withApiAuth($viewer)
            ->getJson('/api/students/export')
            ->assertForbidden();
    }

    /**
     * И то же право по-прежнему показывает список целиком.
     *
     * Сузить видимость заодно — самая вероятная ошибка этой правки, и увидеть её
     * на стенде нечем: смотрят администратором, у которого есть оба права.
     */
    public function test_the_same_role_still_sees_the_whole_list(): void
    {
        $this->seedStudents();
        $viewer = $this->userWith(['students.view'], 'curator');

        $response = $this->withApiAuth($viewer)->getJson('/api/students')->assertOk();

        $this->assertCount(3, $response->json('data'), 'Правка выгрузки сузила заодно и список на экране.');
    }

    public function test_the_role_that_exports_a_selection_takes_the_whole_file_too(): void
    {
        $this->seedStudents();
        $exporter = $this->userWith(['students.view', 'students.bulk_export'], 'study_records');

        $this->withApiAuth($exporter)
            ->get('/api/students/export')
            ->assertOk();
    }
}
