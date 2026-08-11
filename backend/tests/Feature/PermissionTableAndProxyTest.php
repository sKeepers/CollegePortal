<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePermission;
use App\Models\EducationProgram;
use App\Models\Graduate;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Specialty;
use App\Models\Student;
use App\Models\User;
use App\Services\Import\EmployeeImportHandler;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Аудит 08.08.2026, находки 8, 11 и 10.
 */
class PermissionTableAndProxyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Маршруты закрытой части, которым право не нужно.
     *
     * Своей учётной записью, своим рабочим столом и своим кабинетом
     * распоряжается любой вошедший, независимо от роли. Список закрытый: всё
     * остальное обязано объявить право, иначе тест ниже уронит сборку.
     *
     * @var list<string>
     */
    private const OPEN_TO_ANY_AUTHENTICATED = [
        'api/auth/me',
        'api/auth/logout',
        'api/account',
        'api/account/contacts',
        'api/account/password',
        'api/account/identities',
        'api/account/identities/{identity}',
        // Галочки уведомлений: свои, как почта и пароль. Права у них нет намеренно.
        'api/account/notifications',
        'api/dashboard/layouts',
        'api/dashboard/layouts/reset',
        'api/dashboard/layouts/{dashboardLayout}',
        'api/dashboard/layouts/{dashboardLayout}/activate',
        'api/uat/feedback',
    ];

    private function tokenForRole(string $roleCode): string
    {
        $role = Role::where('code', $roleCode)->firstOrFail();
        $token = Str::random(80);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'is_active' => true,
            'api_token_hash' => Hash::make($token),
            'api_token_lookup_hash' => hash('sha256', $token),
            'api_token_expires_at' => now()->addMinutes(720),
        ]);
        $user->roles()->sync([$role->id]);

        return $token;
    }

    /**
     * Находка 8, `ARCH-001`. Право маршрута выводилось из префикса URL, и при
     * промахе молча возвращалось `reference.manage`: законный пользователь
     * получал необъяснимый отказ, а посторонний с `reference.manage` проходил.
     * Так и вышло с фото выпускника — в таблице стояло `alumni`, а контроллер
     * принимает `graduates`.
     *
     * Таблицы больше нет, и промахнуться теперь можно только одним способом —
     * не объявив право вовсе. Тест ловит это здесь, а не у пользователя.
     */
    public function test_every_route_behind_the_token_declares_a_permission(): void
    {
        $missing = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('api.token', $route->gatherMiddleware(), true)) {
                continue;
            }

            if (in_array($route->uri(), self::OPEN_TO_ANY_AUTHENTICATED, true)) {
                continue;
            }

            if ($this->declaredPermissions($route) === []) {
                $missing[] = $route->methods()[0].' '.$route->uri();
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), implode("\n", array_merge(
            [
                'Маршруты закрытой части без объявленного права. Либо допишите',
                '->middleware(\'permission:...\'), либо внесите маршрут в',
                'OPEN_TO_ANY_AUTHENTICATED, если он и правда открыт каждому вошедшему:',
            ],
            array_unique($missing),
        )));
    }

    /**
     * `ARCH-001`, шаг 3. Право-зонтик открывал роли целую группу маршрутов, не
     * требуя ни одного конкретного права. Ни один маршрут больше его не
     * объявляет, и вернуться он не должен: доступ, выданный зонтиком, не виден
     * ни в матрице разрешений, ни в обходе ролей.
     */
    public function test_no_route_declares_a_legacy_umbrella(): void
    {
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($this->declaredPermissions($route) as $permission) {
                if (in_array($permission, EnsurePermission::LEGACY_UMBRELLAS, true)) {
                    $offenders[] = $route->methods()[0].' '.$route->uri().' → '.$permission;
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)), implode("\n", array_merge(
            ['Маршруты объявляют legacy-право-«зонтик» — назовите конкретное право:'],
            array_unique($offenders),
        )));
    }

    /**
     * Каждое право, названное у маршрута, обязано существовать в каталоге.
     * Опечатка в `permission:studnets.view` иначе просто закрывает маршрут
     * всем, кроме администратора, и выглядит как «у роли нет прав».
     */
    public function test_every_declared_permission_exists_in_the_catalogue(): void
    {
        $this->seed(RoleSeeder::class);

        $known = Permission::query()->pluck('code')->all();
        $unknown = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($this->declaredPermissions($route) as $permission) {
                if (! in_array($permission, $known, true)) {
                    $unknown[] = $route->methods()[0].' '.$route->uri().' → '.$permission;
                }
            }
        }

        $this->assertSame([], array_values(array_unique($unknown)), implode("\n", array_merge(
            ['Маршруты требуют прав, которых нет в каталоге `RoleSeeder::permissions()`:'],
            array_unique($unknown),
        )));
    }

    /**
     * Права, объявленные у маршрута. Проверок может быть несколько, и внутри
     * каждой альтернативы перечислены через запятую.
     *
     * @return list<string>
     */
    private function declaredPermissions(RoutingRoute $route): array
    {
        $declared = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                $declared = [...$declared, ...explode(',', substr($middleware, strlen('permission:')))];
            }
        }

        return array_values(array_unique($declared));
    }

    /** Находка 8 на живом маршруте: фото выпускника правит тот, у кого graduation.edit. */
    public function test_study_records_can_upload_a_graduate_photo(): void
    {
        $this->seed(RoleSeeder::class);

        $specialty = Specialty::create(['code' => '53.02.03', 'name' => 'ИИ', 'education_level' => 'СПО', 'qualification' => 'Артист', 'normative_study_years' => 4]);
        $program = EducationProgram::create(['specialty_id' => $specialty->id, 'name' => 'Фортепиано', 'year_start' => 2026, 'study_form' => 'Очная', 'study_years' => 4, 'is_active' => true]);
        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'ИИ', 'course' => 4, 'year_start' => 2023, 'education_program_id' => $program->id]);
        $student = Student::create(['group_id' => $group->id, 'last_name' => 'Иванов', 'first_name' => 'Иван', 'status' => 'active']);
        $graduate = Graduate::create(['student_id' => $student->id, 'group_id' => $group->id, 'education_program_id' => $program->id, 'specialty_id' => $specialty->id, 'graduation_year' => 2026, 'status' => 'draft']);

        $token = $this->tokenForRole('study_records');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->post('/api/person-photos/graduates/'.$graduate->id, ['photo' => UploadedFile::fake()->image('g.jpg')])
            ->assertOk();
    }

    /**
     * Находка 11. За обратным прокси $request->ip() возвращал адрес прокси:
     * журнал аудита писал внутренний адрес контейнера, а ограничитель входа
     * считал попытки всего портала как с одного адреса.
     */
    public function test_forwarded_address_is_taken_only_from_a_trusted_proxy(): void
    {
        $this->seed(RoleSeeder::class);

        $role = Role::where('code', 'teacher')->firstOrFail();
        User::factory()->create([
            'role_id' => $role->id,
            'username' => 'petrova.av',
            'password' => Hash::make('correct-horse-battery'),
            'is_active' => true,
        ]);

        // Прокси из доверенной сети Docker — в аудит идёт адрес человека.
        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.4'])
            ->withHeader('X-Forwarded-For', '203.0.113.10')
            ->postJson('/api/auth/login', ['login' => 'petrova.av', 'password' => 'correct-horse-battery'])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'login', 'ip_address' => '203.0.113.10']);

        // Клиент локальной сети ходит напрямую и подменить свой адрес не может.
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.34.16'])
            ->withHeader('X-Forwarded-For', '198.51.100.7')
            ->postJson('/api/auth/login', ['login' => 'petrova.av', 'password' => 'wrong'])
            ->assertStatus(422);

        $this->assertDatabaseMissing('audit_logs', ['ip_address' => '198.51.100.7']);
    }

    /** Находка 10: выгрузка сотрудников больше не теряет дату рождения и СНИЛС. */
    public function test_employee_template_and_export_carry_birth_date_and_snils(): void
    {
        $handler = app(EmployeeImportHandler::class);
        $headers = $handler->templateHeaders();

        $this->assertContains('Дата рождения', $headers);
        $this->assertContains('СНИЛС', $headers);
        $this->assertCount(count($headers), $handler->templateExample(), 'пример строки разошёлся с заголовками');

        $this->withApiAuth();

        $person = Person::create(['last_name' => 'Примерова', 'first_name' => 'Александра', 'birth_date' => '1990-03-15', 'snils' => '12345678901']);
        \App\Models\Employee::create([
            'person_id' => $person->id,
            'employee_number' => 'T-001',
            'status' => 'active',
            'employment_type' => 'full_time',
            'hired_at' => '2026-09-01',
        ]);

        $csv = $this->get('/api/employees/export')->assertOk()->streamedContent();

        $this->assertStringContainsString('Дата рождения', $csv);
        $this->assertStringContainsString('1990-03-15', $csv);
        $this->assertStringContainsString('12345678901', $csv);
    }
}
