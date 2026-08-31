<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Спрошенное у базы про права запоминается — но ровно на один запрос и ровно
 * для одного человека.
 *
 * **Зачем это вообще.** `StudentResource` спрашивает про право семь раз на
 * каждую строку, а `hasPermission()` начинается с запроса в базу. Замер
 * 01.09.2026 на копии базы стенда, страница из 500 студентов: администратор
 * 2,17 с и 3 500 запросов, комендант 10,12 с и 13 500, преподаватель 11,47 с и
 * 14 000. С памятью — 0,3 с и до шести запросов.
 *
 * **Но выигрыш здесь дешевле правильности.** Память ответов может ошибиться
 * двумя способами, и оба хуже медленного экрана, потому что незаметны:
 * ответить чужими правами и ответить вчерашними. Здесь закрыты оба.
 */
class PermissionAnswersTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $roleCode): User
    {
        $role = Role::query()->firstOrCreate(
            ['code' => $roleCode],
            ['name' => $roleCode, 'description' => null],
        );

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    /**
     * Второй и следующие одинаковые вопросы базу не трогают.
     *
     * Порог здесь не назначается числом: сколько стоит **первый** вопрос —
     * свойство `hasPermission()` (у не-администратора до четырёх запросов), и
     * привязываться к нему значило бы краснеть от любой правки внутри. Меряется
     * то, ради чего правка делалась: **что стоят девятнадцать повторов**.
     */
    public function test_the_same_question_reaches_the_database_once(): void
    {
        $user = $this->userWithRole('commandant');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $user->hasPermission('students.update');
        $first = count(DB::getQueryLog());

        DB::flushQueryLog();
        for ($i = 0; $i < 19; $i++) {
            $user->hasPermission('students.update');
        }
        $repeats = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $first, 'Первый вопрос обязан дойти до базы — иначе проверяется не то.');
        $this->assertSame(
            0,
            $repeats,
            "Девятнадцать повторов стоили {$repeats} запросов при {$first} на первый — память ответов не работает.",
        );
    }

    /**
     * Два человека не делят ответы.
     *
     * Проверка кажется избыточной, пока память лежит на объекте. Она стоит
     * здесь на случай, когда её захотят перенести «повыше» — в запрос или в
     * статическое поле: тогда права одного покажутся другому, и заметить это
     * будет нечем.
     */
    public function test_two_people_do_not_share_answers(): void
    {
        $admin = $this->userWithRole('admin');
        $student = $this->userWithRole('student');

        $this->assertTrue($admin->hasPermission('students.update'));
        $this->assertFalse(
            $student->hasPermission('students.update'),
            'Студенту достался ответ администратора — память ответов общая на всех.',
        );
    }

    /**
     * Под чужими глазами приходят права того, на кого смотрят.
     *
     * Самое опасное из возможного: режим просмотра показал бы не то, что видит
     * человек, а то, что видит смотрящий, — и выглядело бы это как рабочий
     * портал. Проверяется наблюдаемым признаком: паспортные поля в списке
     * студентов отдаются только по праву `students.update`.
     */
    public function test_looking_through_someone_eyes_answers_with_their_rights(): void
    {
        $admin = $this->userWithRole('admin');
        $watched = $this->userWithRole('security');

        $view = Permission::query()->firstOrCreate(
            ['code' => 'students.view'],
            ['module' => 'Study', 'name' => 'Студенты: просмотр', 'description' => null, 'system' => true, 'active' => true],
        );
        Role::query()->where('code', 'security')->first()->permissions()->syncWithoutDetaching([$view->id]);

        $group = Group::create(['name' => 'ИСП-201', 'specialty' => 'Информационные системы', 'course' => 2, 'year_start' => 2025]);
        Student::create([
            'group_id' => $group->id,
            'last_name' => 'Полуночный',
            'first_name' => 'Иван',
            'snils' => '111-222-333 44',
            'status' => 'active',
        ]);

        // Администратор спрашивает про право **до** входа в режим, и это не
        // украшение проверки, а её суть: пока этой строки не было, сторож
        // оставался зелёным на внесённом дефекте — память, сделанная общей на
        // всех, ему была не видна, потому что администратор про это право
        // просто не спрашивал. Проверка охраняла не то, о чём её спрашивали.
        $this->assertTrue($admin->hasPermission('students.update'));

        $admin->forceFill(['viewing_as_user_id' => $watched->id])->save();
        $this->withApiAuth($admin);

        $row = $this->getJson('/api/students?per_page=10')->assertOk()->json('data.0');

        $this->assertArrayNotHasKey(
            'snils',
            $row,
            'Под чужими глазами пришли права администратора: СНИЛС отдан тому, у кого нет права на правку.',
        );
    }

    /**
     * Следующий запрос спрашивает заново.
     *
     * У нас записано, что в тестах контейнер переживает запрос и `Auth::setUser`
     * протекает вперёд — на этом уже один тест проходил по неверной причине.
     * Здесь проверяется обратное: право, выданное между двумя запросами,
     * действует со второго, а не «когда-нибудь».
     */
    public function test_the_next_request_asks_again(): void
    {
        $user = $this->userWithRole('security');
        $this->withApiAuth($user);

        $this->getJson('/api/students?per_page=1')->assertForbidden();

        $view = Permission::query()->firstOrCreate(
            ['code' => 'students.view'],
            ['module' => 'Study', 'name' => 'Студенты: просмотр', 'description' => null, 'system' => true, 'active' => true],
        );
        Role::query()->where('code', 'security')->first()->permissions()->syncWithoutDetaching([$view->id]);

        // Резолвер объявлен `scoped` и в бою живёт один запрос; в тестах
        // контейнер переживает их все, поэтому его надо забыть руками — иначе
        // проверка мерила бы живучесть контейнера, а не память ответов.
        $this->app->forgetInstance(\App\Support\Auth\ApiTokenResolver::class);

        $this->getJson('/api/students?per_page=1')->assertOk();
    }

    /** Список не спрашивает базу про право на каждой строке. */
    public function test_a_list_does_not_ask_about_rights_row_by_row(): void
    {
        $user = $this->userWithRole('admin');
        $group = Group::create(['name' => 'ИСП-202', 'specialty' => 'Информационные системы', 'course' => 2, 'year_start' => 2025]);

        foreach (range(1, 25) as $n) {
            Student::create([
                'group_id' => $group->id,
                'last_name' => 'Студентов'.$n,
                'first_name' => 'Иван',
                'status' => 'active',
            ]);
        }

        $this->withApiAuth($user);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/students?per_page=25')->assertOk();
        $roleQueries = collect(DB::getQueryLog())
            ->filter(fn (array $q) => str_contains($q['query'], '"roles"'))
            ->count();
        DB::disableQueryLog();

        // Двадцать пять строк по семь вопросов дали бы под сотню запросов.
        $this->assertLessThanOrEqual(
            5,
            $roleQueries,
            "Список из 25 строк стоил {$roleQueries} запросов к ролям — на 596 студентах это тысячи.",
        );
    }
}
