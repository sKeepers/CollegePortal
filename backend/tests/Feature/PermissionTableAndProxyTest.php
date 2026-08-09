<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePermission;
use App\Models\EducationProgram;
use App\Models\Graduate;
use App\Models\Group;
use App\Models\Person;
use App\Models\Role;
use App\Models\Specialty;
use App\Models\Student;
use App\Models\User;
use App\Services\Import\EmployeeImportHandler;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
     * Находка 8, ARCH-001. Право маршрута выводится из префикса URL, и при
     * промахе молча возвращается reference.manage: законный пользователь
     * получает необъяснимый отказ, а посторонний с reference.manage проходит.
     * Так и вышло с фото выпускника — в таблице стоял alumni, а контроллер
     * принимает graduates.
     *
     * Тест держит таблицу полной: новый маршрут в группе manage_dictionaries,
     * префикс которого сюда не внесли, роняет сборку здесь, а не у пользователя.
     */
    public function test_every_dictionary_route_has_a_prefix_in_the_permission_table(): void
    {
        $missing = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('permission:manage_dictionaries', $route->gatherMiddleware(), true)) {
                continue;
            }

            foreach ($this->concretePaths($route->uri()) as $path) {
                $matched = false;

                foreach (array_keys(EnsurePermission::DOMAIN_RULES) as $prefix) {
                    if (str_starts_with($path, $prefix)) {
                        $matched = true;
                        break;
                    }
                }

                if (! $matched) {
                    $missing[] = $path;
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), implode("\n", array_merge(
            ['Маршруты вне таблицы EnsurePermission::DOMAIN_RULES — они молча получат reference.manage:'],
            array_unique($missing),
        )));
    }

    /**
     * Параметры подставляем единицей, а тип фотографии разворачиваем во все три
     * реальных значения: именно на нём таблица и разъехалась с контроллером.
     *
     * @return list<string>
     */
    private function concretePaths(string $uri): array
    {
        if (str_contains($uri, '{type}')) {
            return array_map(
                fn (string $type): string => (string) preg_replace('/\{[^}]+\}/', '1', str_replace('{type}', $type, $uri)),
                ['students', 'teachers', 'graduates'],
            );
        }

        return [(string) preg_replace('/\{[^}]+\}/', '1', $uri)];
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
