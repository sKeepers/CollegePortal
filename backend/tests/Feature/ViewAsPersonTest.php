<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Портал глазами другого человека: только просмотр, и след не врёт.
 *
 * Здесь закреплено не «работает ли режим», а пять вещей, каждая из которых
 * ломается тихо и выглядит при этом исправной:
 *
 * 1. ограничитель частоты считает администратора, а не просматриваемого;
 * 2. изменяющий запрос отвергается **на сервере**, а не прячется на экране;
 * 3. выгрузки не уходят — их 29 из 31 по `GET`, и запрет по методу их не берёт;
 * 4. журнал называет действующим настоящего администратора;
 * 5. выход из портала снимает режим.
 *
 * Разбор — `docs/VIEW_AS_PERSON.md`.
 */
class ViewAsPersonTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return $this->createApiUser(null, 'admin');
    }

    private function student(): User
    {
        $user = $this->createApiUser(null, 'student');
        $user->forceFill(['name' => 'Полуночный Иван'])->save();

        return $user;
    }

    private function watching(User $admin, User $target): User
    {
        $admin->forceFill(['viewing_as_user_id' => $target->id])->save();

        // `ApiTokenResolver` объявлен `scoped` и запоминает разобранного
        // владельца токена. В бою контейнер живёт один запрос, а в тестах
        // переживает их все — и следующий запрос получил бы модель, прочитанную
        // **до** этой строки, то есть режим бы не включился. Тест краснел бы,
        // выглядя как дефект кода.
        $this->app->forgetInstance(\App\Support\Auth\ApiTokenResolver::class);

        return $admin;
    }

    public function test_the_portal_answers_as_the_person_being_watched(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $this->watching($admin, $student);
        $this->withApiAuth($admin);

        $response = $this->getJson('/api/auth/me')->assertOk();

        $this->assertSame($student->id, $response->json('data.id'), 'Экраны обязаны строиться по просматриваемому, иначе весь режим бессмыслен.');
        $this->assertSame($student->id, $response->json('viewing_as.id'));
        $this->assertSame($admin->id, $response->json('impersonator.id'), 'Полоса на экране строится отсюда — без этого поля её нечем нарисовать.');
    }

    /**
     * Ограничитель частоты считает того, кто на самом деле шлёт запросы.
     *
     * Прямая проверка того, что подмена **не уехала в `ApiTokenResolver`**.
     * Резолвера спрашивает ограничитель, и он стоит впереди аутентификации —
     * подмени владельца там, и портал начнёт ограничивать просматриваемого,
     * который в этот момент вообще ничего не делает.
     */
    public function test_the_rate_limiter_still_counts_the_administrator(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $this->watching($admin, $student);
        $this->withApiAuth($admin);

        $this->getJson('/api/auth/me')->assertOk();

        $resolved = app(\App\Support\Auth\ApiTokenResolver::class)->resolve(request());

        $this->assertNotNull($resolved);
        $this->assertSame($admin->id, $resolved->id, 'Резолвер обязан отдавать администратора: по нему считается частота запросов.');
    }

    public function test_a_changing_request_is_refused_by_the_server(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $this->withApiAuth($admin);

        // Сначала убеждаемся, что без режима этот же запрос доходит до дела, —
        // иначе проверка прошла бы по неверной причине: маршрут мог бы отвечать
        // отказом сам по себе, и режим был бы ни при чём. Пустое тело даёт 422 от
        // проверки полей, то есть запрос дошёл до контроллера.
        $this->postJson('/api/groups', [])->assertStatus(422);

        $this->watching($admin, $student);

        $this->postJson('/api/groups', [])
            ->assertForbidden()
            ->assertJsonPath('message', 'Портал открыт глазами другого человека — это только просмотр. Выйдите из режима, чтобы вносить изменения.');
    }

    /**
     * Выгрузки закрыты, и закрыты не методом.
     *
     * Замер 31.08.2026: маршрутов выгрузки 31, из них **29 отдают файл по
     * `GET`**. Запрет по методу выглядел бы запретом и не запрещал бы ни одной.
     */
    public function test_no_export_hands_out_a_file(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $this->watching($admin, $student);
        $this->withApiAuth($admin);

        $this->getJson('/api/students/export')
            ->assertForbidden()
            ->assertJsonPath('message', 'Выгрузка под чужими глазами запрещена. Выйдите из режима и выгрузите от своего имени.');
    }

    /**
     * Настоящие маршруты портала, отдающие файл, отвергаются на деле.
     *
     * Маршруты берутся **у роутера**, а не переписаны сюда руками: список,
     * записанный в тест, однажды разойдётся с приложением и промолчит. И
     * проверяется не согласие правила с самим собой, а **ответ портала** —
     * запрос уходит и обязан вернуться отказом.
     *
     * Первая редакция запрета ловила одно слово `export`, и этот обход нашёл
     * семь дыр: пакет ФИС целиком, документы абитуриентов, файлы журнала
     * занятия. То есть мимо запрета уходило ровно то, что опаснее всего.
     */
    public function test_the_real_file_routes_of_the_portal_are_refused(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $this->watching($admin, $student);
        $this->withApiAuth($admin);

        $tried = 0;

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            // Только адреса без подстановок: остальные не позвать, не заводя
            // сущности, а проверяем мы здесь запрет, а не контроллеры.
            if (! in_array('GET', $route->methods(), true) || str_contains($uri, '{')) {
                continue;
            }

            if (! \App\Http\Middleware\ViewAsPerson::pathHandsOutAFile($uri)) {
                continue;
            }

            $tried++;

            $this->getJson('/'.$uri)
                ->assertForbidden()
                ->assertJsonPath('message', 'Выгрузка под чужими глазами запрещена. Выйдите из режима и выгрузите от своего имени.');
        }

        // Счётчик, ни разу не ответивший «да», подозрителен сам по себе: без этой
        // строки сторож зеленел бы и на пустом списке маршрутов.
        $this->assertGreaterThan(20, $tried, "Проверено маршрутов: {$tried} — сторож ищет не то, что охраняет.");
    }

    /**
     * Второй заслон: файл, отданный по адресу, который не выглядит выгрузкой.
     *
     * Здесь и лежит настоящая ценность проверки — она не спрашивает правило о
     * нём самом. Маршрут назван так, что признак по имени его не берёт, и
     * отказать может **только** разбор ответа.
     */
    public function test_a_file_handed_out_from_an_innocent_looking_address_is_refused(): void
    {
        Route::middleware(['api.token', 'api.csrf', 'throttle:api.authenticated', 'view.as'])
            ->get('api/quiet-corner', fn () => response('Фамилия;Группа')
                ->header('Content-Disposition', 'attachment; filename="quiet.csv"'));

        $admin = $this->admin();
        $student = $this->student();
        $this->watching($admin, $student);
        $this->withApiAuth($admin);

        $this->assertFalse(
            \App\Http\Middleware\ViewAsPerson::pathHandsOutAFile('api/quiet-corner'),
            'Адрес обязан быть невинным с виду, иначе проверяется не тот заслон.',
        );

        $this->getJson('/api/quiet-corner')->assertForbidden();
    }

    public function test_the_journal_names_the_administrator_and_not_the_watched(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $this->withApiAuth($admin);

        $this->postJson("/api/admin/view-as/{$student->id}")->assertOk();

        $entry = AuditLog::query()->where('module', 'impersonation')->where('action', 'start')->latest('id')->first();

        $this->assertNotNull($entry, 'Вход в режим обязан оставлять запись: это защита самого владельца.');
        $this->assertSame($admin->id, $entry->user_id, 'Действующим записан просматриваемый — журнал научился врать.');
        $this->assertSame($student->id, $entry->viewed_as_user_id, 'На вопрос «кто и чьими глазами» отвечает пара колонок в одной строке.');

        $this->deleteJson('/api/admin/view-as')->assertOk();

        $stop = AuditLog::query()->where('module', 'impersonation')->where('action', 'stop')->latest('id')->first();

        $this->assertNotNull($stop, 'Выход из режима обязан оставлять запись: иначе непонятно, когда просмотр кончился.');
        $this->assertSame($admin->id, $stop->user_id);
        $this->assertSame($student->id, $stop->viewed_as_user_id);
    }

    /**
     * Чтение, которое пишет в журнал, называет действующим администратора.
     *
     * Отдельный тест понадобился потому, что **без него подмена действующего в
     * `AuditLogService` не проверялась ничем**: вход в режим и выход из него
     * пишут администратора явно, а больше под чужими глазами сегодня писать
     * некому — запись запрещена, выгрузки тоже. Внесённый дефект это и показал:
     * все двенадцать проверок остались зелёными.
     *
     * Код при этом не мёртвый, и вот почему он нужен: запрет на выгрузки
     * поставлен словами владельца «пока что». В день, когда его снимут, выгрузка
     * под чужими глазами начнёт писать след — и вот тогда `user_id` обязан
     * назвать администратора, а не того, на кого смотрят. Проверка держит
     * именно этот день.
     */
    public function test_a_read_that_writes_to_the_journal_names_the_administrator(): void
    {
        Route::middleware(['api.token', 'api.csrf', 'throttle:api.authenticated', 'view.as'])
            ->get('api/quiet-read', function () {
                \App\Services\AuditLogService::log('проверка', 'read');

                return response()->json(['ok' => true]);
            });

        $admin = $this->admin();
        $student = $this->student();
        $this->watching($admin, $student);
        $this->withApiAuth($admin);

        $this->getJson('/api/quiet-read')->assertOk();

        $entry = AuditLog::query()->where('module', 'проверка')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame($admin->id, $entry->user_id, 'Действующим записан просматриваемый — журнал научился врать.');
        $this->assertSame($student->id, $entry->viewed_as_user_id, 'Чьими глазами смотрели, обязано стоять отдельной колонкой.');
    }

    public function test_leaving_the_portal_takes_the_mode_off(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $this->watching($admin, $student);
        $this->withApiAuth($admin);

        $this->postJson('/api/auth/logout')->assertOk();

        $this->assertNull($admin->fresh()->viewing_as_user_id, 'Иначе следующий вход начнётся чужими глазами, и человек этого не ждёт.');
    }

    /**
     * Новый вход начинается своими глазами.
     *
     * Найдено взглядом через браузер, а не запросом, и запросом бы не нашлось:
     * фронтенд после входа берёт человека из ответа входа, а не из `auth/me`, и
     * про режим не узнаёт. То есть сессия, начатая при включённом режиме,
     * работала бы чужими глазами **без полосы** — человек не знал бы, что он не
     * он. Сервер закрывает это сам, не полагаясь на экран.
     */
    public function test_a_new_login_starts_with_your_own_eyes(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $admin->forceFill([
            'viewing_as_user_id' => $student->id,
            'password' => \Illuminate\Support\Facades\Hash::make('Пароль-31-08'),
        ])->save();

        $this->postJson('/api/auth/login', ['login' => $admin->email, 'password' => 'Пароль-31-08'])->assertOk();

        $this->assertNull($admin->fresh()->viewing_as_user_id, 'Режим пережил вход — портал заработает чужими глазами, а полосы не будет.');
    }

    public function test_the_way_out_works_from_inside_the_mode(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $this->watching($admin, $student);
        $this->withApiAuth($admin);

        $this->deleteJson('/api/admin/view-as')->assertOk();

        $this->assertNull($admin->fresh()->viewing_as_user_id, 'Выход изменяющий, и запрет на запись отверг бы его первым — из режима стало бы не выбраться.');
    }

    public function test_looking_through_another_administrator_is_refused(): void
    {
        $admin = $this->admin();
        $other = $this->createApiUser(null, 'admin');
        $this->withApiAuth($admin);

        $this->postJson("/api/admin/view-as/{$other->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Глазами другого администратора смотреть нельзя.');
    }

    public function test_a_watcher_cannot_gain_a_right_of_their_own(): void
    {
        $watcher = $this->createApiUser(null, 'study_records');
        $target = $this->createApiUser(null, 'commandant');

        $rare = Permission::query()->create([
            'module' => 'System',
            'code' => 'nobody.else.has.this',
            'name' => 'Право, которого нет у смотрящего',
            'description' => null,
            'system' => false,
            'active' => true,
        ]);
        Role::query()->where('code', 'commandant')->first()->permissions()->syncWithoutDetaching([$rare->id]);
        Role::query()->where('code', 'study_records')->first()->permissions()->syncWithoutDetaching(
            Permission::query()->where('code', 'users.view_as')->pluck('id')->all(),
        );

        $this->withApiAuth($watcher);

        // Проверяем вхождением, а не равенством: у коменданта своих прав хватает,
        // и список недостающих законно длиннее одного. Жёсткая строка сломалась бы
        // от любой правки набора прав роли — то есть краснела бы не по делу.
        $response = $this->postJson("/api/admin/view-as/{$target->id}")->assertStatus(422);

        $this->assertStringContainsString('nobody.else.has.this', $response->json('message'));
        $this->assertNull($watcher->fresh()->viewing_as_user_id, 'Отказ обязан ничего не включать.');
    }

    public function test_the_permission_arrives_by_migration_and_not_only_by_the_seeder(): void
    {
        // База поднята одними миграциями: `RefreshDatabase` сидер не гоняет.
        // Именно так живёт уже установленный портал — `installer/update.sh`
        // выполняет миграции и ничего кроме.
        $this->assertDatabaseHas('permissions', ['code' => 'users.view_as']);

        $admin = Role::query()->where('code', 'admin')->first();

        $this->assertNotNull($admin);
        $this->assertTrue(
            $admin->permissions()->where('code', 'users.view_as')->exists(),
            'Право есть в каталоге, но роли не выдано — на боевом портале режим не откроется.',
        );
    }
}
