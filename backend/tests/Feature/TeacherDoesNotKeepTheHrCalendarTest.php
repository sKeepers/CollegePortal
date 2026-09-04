<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Календарь отсутствий снят у преподавателя — и только у него.
 *
 * Решение владельца 04.09.2026: что роли разрешено, то ей и показывается, а
 * `/hr/calendar` преподавателю не нужен. Значит право у роли остаться не может:
 * иначе экран пришлось бы показать по общему правилу.
 *
 * **Проверка стоит на обе стороны, и вторая здесь дороже первой.** Право держали
 * восемь ролей, а набор куратора в сидере **собирается из набора преподавателя**
 * (`RoleSeeder`, строка с `array_merge($this->teacherPermissions(), …)`) — то
 * есть снятие у преподавателя забрало бы календарь и у куратора, молча и за
 * компанию. Поэтому рядом с «у преподавателя нет» стоит «у остальных семи
 * есть», с поимённым перечнем: сузить лишнего — самая вероятная ошибка такой
 * правки, и на стенде её не заметить.
 *
 * Проверяются **оба** источника, потому что расходятся они молча: миграции
 * (обновление установленного портала) и сидер (новая установка). Право,
 * снятое только миграцией, вернулось бы на новой установке; снятое только в
 * сидере — не доехало бы до боевого.
 */
class TeacherDoesNotKeepTheHrCalendarTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = 'hr.calendar.view';

    /** Кому календарь остаётся. Перечень поимённый: число сказало бы меньше. */
    private const KEEPS = ['academic_office', 'admin', 'curator', 'deputy', 'director', 'hr', 'study'];

    public function test_the_teacher_loses_the_calendar_and_the_others_keep_it(): void
    {
        $this->seed(RoleSeeder::class);

        $this->assertFalse($this->holds('teacher'),
            'У преподавателя осталось право на календарь отсутствий — по общему правилу «что разрешено, то и показывается» экран пришлось бы открыть.');

        foreach (self::KEEPS as $role) {
            $this->assertTrue($this->holds($role),
                'Роль «'.$role.'» потеряла календарь отсутствий заодно с преподавателем: правка сузила лишнего.');
        }
    }

    /**
     * Куратор теряет календарь легче всех, и не по своей вине.
     *
     * Его набор в сидере собран из набора преподавателя, поэтому проверка
     * названа отдельно: если однажды строку с явным `hr.calendar.view` уберут
     * как «дубль», красным станет именно это утверждение, а не общее.
     */
    public function test_the_curator_keeps_the_calendar_though_the_set_is_built_from_the_teacher(): void
    {
        $this->seed(RoleSeeder::class);

        $this->assertTrue($this->holds('curator'),
            'Куратор потерял календарь вместе с преподавателем: его набор собирается из набора преподавателя, и право нужно называть явно.');
    }

    /**
     * Обновление установленного портала снимает право так же, как новая установка.
     *
     * Сидер при обновлении не выполняется никогда, поэтому одного сидера мало:
     * на боевом право осталось бы у роли, и экран открылся бы там, где решено
     * его закрыть.
     *
     * **Проверять это на голых миграциях бессмысленно, и первая попытка была
     * именно такой — зелёной по неверной причине.** Каталог прав заводится
     * сидером при установке, поэтому в базе после одних миграций
     * `hr.calendar.view` попросту нет, снимать нечего, и утверждение проходило,
     * ничего не проверив. Здесь воспроизведён настоящий путь боевого: установка
     * (сидер), состояние «право у преподавателя было», обновление (миграция).
     */
    public function test_the_update_takes_the_right_away_too_and_not_only_a_fresh_install(): void
    {
        $this->seed(RoleSeeder::class);

        $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id');
        $teacherId = DB::table('roles')->where('code', 'teacher')->value('id');
        $this->assertNotNull($permissionId, 'Права нет в каталоге после установки — проверка мерила бы пустоту.');
        $this->assertNotNull($teacherId, 'Роли `teacher` нет после установки.');

        // Состояние установленного портала до решения владельца: право у роли есть.
        DB::table('permission_role')->insertOrIgnore(['role_id' => $teacherId, 'permission_id' => $permissionId]);
        $this->assertTrue($this->holds('teacher'), 'Не удалось воспроизвести состояние «право было» — дальше проверять нечего.');

        $migration = require database_path('migrations/2026_09_04_000001_the_teacher_does_not_keep_the_hr_calendar.php');
        $migration->up();

        $this->assertFalse($this->holds('teacher'),
            'Обновление не сняло право: на боевом экран откроется вопреки решению.');
        $this->assertTrue($this->holds('curator'),
            'Обновление сняло право заодно у куратора.');
        $this->assertTrue($this->holds('hr'),
            'Обновление сняло право заодно у кадров.');
    }

    private function holds(string $role): bool
    {
        return Role::query()->where('code', $role)->firstOrFail()
            ->permissions()->where('code', self::CODE)->exists();
    }
}
