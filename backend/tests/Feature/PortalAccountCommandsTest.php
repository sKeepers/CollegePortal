<?php

namespace Tests\Feature;

use App\Models\DigitalIdentity;
use App\Models\Employee;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PortalAccountCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_account_command_creates_person_employee_user_and_pass(): void
    {
        $this->seed(RoleSeeder::class);

        $this->artisan('portal:staff-account', [
            '--email' => 'director@local',
            '--role' => 'director',
            '--last-name' => 'Петрова',
            '--first-name' => 'Мария',
            '--middle-name' => 'Ивановна',
            '--phone' => '8 (900) 111-22-33',
            '--position' => 'Директор',
            '--password' => 'stand-password',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'director@local')->firstOrFail();
        $this->assertSame('Петрова Мария Ивановна', $user->name);
        $this->assertSame('director', $user->username);
        $this->assertSame('director', $user->role()->first()?->code);
        $this->assertTrue($user->roles()->where('code', 'director')->exists());
        $this->assertTrue(Hash::check('stand-password', $user->password));

        $person = Person::query()->findOrFail($user->person_id);
        // Телефон приводится к единому написанию: вход по телефону и поиск
        // дубликатов сравнивают строки, а не цифры.
        $this->assertSame('+79001112233', $person->phone);

        $employee = Employee::query()->where('person_id', $person->id)->firstOrFail();
        $this->assertSame('active', $employee->status);
        $this->assertSame('Директор', $employee->primaryPosition?->name);

        $this->assertTrue(
            DigitalIdentity::query()
                ->where('entity_type', 'employee')
                ->where('entity_id', $employee->id)
                ->where('status', DigitalIdentity::STATUS_ACTIVE)
                ->exists(),
            'Сотруднику должен быть выпущен QR-пропуск',
        );
    }

    /**
     * Повторный вызов не должен отзывать уже выданный QR: распечатанный пропуск
     * перестал бы открывать турникет.
     */
    public function test_staff_account_command_is_repeatable_and_keeps_the_issued_pass(): void
    {
        $this->seed(RoleSeeder::class);

        $arguments = [
            '--email' => 'hr@local',
            '--role' => 'hr',
            '--last-name' => 'Сидорова',
            '--first-name' => 'Ольга',
            '--phone' => '+79001112244',
        ];

        $this->artisan('portal:staff-account', $arguments)->assertSuccessful();
        $firstToken = DigitalIdentity::query()->where('entity_type', 'employee')->value('token');

        $this->artisan('portal:staff-account', $arguments)->assertSuccessful();

        $this->assertSame(1, User::query()->where('email', 'hr@local')->count());
        $this->assertSame(1, Employee::query()->count());
        $this->assertSame(1, DigitalIdentity::query()->where('entity_type', 'employee')->count());
        $this->assertSame($firstToken, DigitalIdentity::query()->where('entity_type', 'employee')->value('token'));
    }

    /**
     * Телефон живой карточки записан без плюса, а команда нормализует его к
     * +7XXXXXXXXXX. Сравнение строк один в один заводило второго Person на того
     * же человека и вторую карточку кадров рядом с уже существующей.
     */
    public function test_staff_account_command_finds_a_person_whose_phone_is_stored_differently(): void
    {
        $this->seed(RoleSeeder::class);

        $existing = Person::query()->create([
            'last_name' => 'Горбачева',
            'first_name' => 'Татьяна',
            'middle_name' => 'Владимировна',
            'phone' => '79620005050',
            'email' => 'info@example.test',
            'status' => 'active',
        ]);
        $card = Employee::query()->create([
            'person_id' => $existing->id,
            'employee_number' => 'EMP-000001',
            'status' => 'active',
            'employment_type' => 'full_time',
            'hired_at' => '2020-01-01',
            'is_teacher' => true,
        ]);

        $this->artisan('portal:staff-account', [
            '--email' => 'director@local',
            '--role' => 'director',
            '--last-name' => 'Горбачева',
            '--first-name' => 'Татьяна',
            '--middle-name' => 'Владимировна',
            '--phone' => '+7 (962) 000-50-50',
        ])->assertSuccessful();

        $this->assertSame(1, Person::query()->count(), 'Второй карточки человека быть не должно');
        $this->assertSame(1, Employee::query()->count(), 'Второй карточки кадров быть не должно');
        $this->assertSame($existing->id, User::query()->where('email', 'director@local')->value('person_id'));

        // Дата приема, тип занятости и признак преподавателя принадлежат кадрам,
        // и повторное заведение учетной записи их не переписывает.
        $card->refresh();
        $this->assertTrue($card->is_teacher);
        $this->assertSame('2020-01-01', $card->hired_at->toDateString());
        $this->assertSame('EMP-000001', $card->employee_number);
    }

    public function test_staff_account_command_allows_a_card_without_the_last_name(): void
    {
        $this->seed(RoleSeeder::class);

        $this->artisan('portal:staff-account', [
            '--email' => 'study.records@local',
            '--role' => 'study_records',
            '--first-name' => 'Аня',
            '--phone' => '+79001112255',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'study.records@local')->firstOrFail();
        $this->assertSame('Аня', $user->name);
        $this->assertSame('study_records', $user->role()->first()?->code);
    }

    /**
     * Слияние обязано оставить учетную запись со связями. У исторической
     * «teacher1.uat» есть Teacher и Person, у одноименной «teacher» их нет:
     * выбор по возрасту записи оставил бы преподавателя без профиля, и журнал
     * перестал бы открываться.
     */
    public function test_merge_keeps_the_account_that_owns_the_profile(): void
    {
        $this->seed(RoleSeeder::class);
        $teacherRole = Role::query()->where('code', 'teacher')->firstOrFail();

        $withProfile = User::query()->create([
            'email' => 'teacher1.uat@college-portal.local',
            'name' => 'Преподаватель UAT',
            'password' => Hash::make('secret12345'),
            'role_id' => $teacherRole->id,
            'is_active' => true,
        ]);
        $person = Person::query()->create(['last_name' => 'Смирнова', 'first_name' => 'Елена', 'status' => 'active']);
        $withProfile->forceFill(['person_id' => $person->id, 'person_type' => 'person'])->save();
        Teacher::query()->create([
            'person_id' => $person->id,
            'user_id' => $withProfile->id,
            'last_name' => 'Смирнова',
            'first_name' => 'Елена',
            'email' => 'teacher1.uat@college-portal.local',
            'is_active' => true,
        ]);

        $withoutProfile = User::query()->create([
            'email' => 'teacher@college-portal.local',
            'name' => 'Преподаватель DEV',
            'password' => Hash::make('secret12345'),
            'role_id' => $teacherRole->id,
            'is_active' => true,
        ]);

        $this->artisan('portal:merge-accounts', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $withoutProfile->id]);
        $survivor = User::query()->findOrFail($withProfile->id);
        $this->assertSame('teacher@local', $survivor->email);
        $this->assertSame('teacher', $survivor->username);
        $this->assertSame($person->id, $survivor->person_id);
        $this->assertNotNull($survivor->teacher()->first());
    }

    /**
     * Приставка стенда обязана уйти и из имени: владелец видел «Директор UAT»
     * в списке пользователей и в шапке портала, а жаловался именно на это.
     */
    public function test_merge_strips_the_stand_marker_from_the_name(): void
    {
        $this->seed(RoleSeeder::class);

        User::query()->create([
            'email' => 'deputy.uat@college-portal.local',
            'name' => 'Заместитель директора UAT',
            'password' => Hash::make('secret12345'),
            'role_id' => Role::query()->where('code', 'deputy')->value('id'),
            'is_active' => true,
        ]);

        $this->artisan('portal:merge-accounts', ['--apply' => true])->assertSuccessful();

        $this->assertSame('Заместитель директора', User::query()->where('email', 'deputy@local')->value('name'));
    }

    /**
     * Настоящее ФИО живого сотрудника слияние переписывать не должно.
     */
    public function test_merge_keeps_a_real_name(): void
    {
        $this->seed(RoleSeeder::class);

        User::query()->create([
            'email' => 'ok@skki.ru',
            'name' => 'Власова Елена Александровна',
            'password' => Hash::make('secret12345'),
            'role_id' => Role::query()->where('code', 'hr')->value('id'),
            'is_active' => true,
        ]);

        $this->artisan('portal:merge-accounts', ['--apply' => true])->assertSuccessful();

        $this->assertSame('Власова Елена Александровна', User::query()->where('email', 'hr@local')->value('name'));
    }

    public function test_merge_without_apply_changes_nothing(): void
    {
        $this->seed(RoleSeeder::class);

        User::query()->create([
            'email' => 'director.uat@college-portal.local',
            'name' => 'Директор UAT',
            'password' => Hash::make('secret12345'),
            'role_id' => Role::query()->where('code', 'director')->value('id'),
            'is_active' => true,
        ]);

        $this->artisan('portal:merge-accounts')->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'director.uat@college-portal.local']);
        $this->assertDatabaseMissing('users', ['email' => 'director@local']);
    }

    /**
     * Профиль студента переносится на выжившую учетную запись, иначе личный
     * кабинет и личный QR после слияния разрешаются в пустоту.
     */
    public function test_merge_moves_the_student_profile_to_the_survivor(): void
    {
        $this->seed(RoleSeeder::class);
        $studentRole = Role::query()->where('code', 'student')->firstOrFail();
        $group = \App\Models\Group::query()->create(['name' => 'ТЕСТ-11', 'specialty' => 'Тестовая', 'course' => 1, 'year_start' => 2026]);

        $legacy = User::query()->create([
            'email' => 'student1.uat@college-portal.local',
            'name' => 'Студент UAT',
            'password' => Hash::make('secret12345'),
            'role_id' => $studentRole->id,
            'is_active' => true,
        ]);
        $person = Person::query()->create(['last_name' => 'Иванов', 'first_name' => 'Дмитрий', 'status' => 'active']);
        Student::query()->create([
            'person_id' => $person->id,
            'user_id' => $legacy->id,
            'group_id' => $group->id,
            'course' => 1,
            'last_name' => 'Иванов',
            'first_name' => 'Дмитрий',
            'email' => 'student1.uat@college-portal.local',
            'status' => 'active',
        ]);

        $canonical = User::query()->create([
            'email' => 'student@local',
            'name' => 'Студент',
            'password' => Hash::make('secret12345'),
            'role_id' => $studentRole->id,
            'is_active' => true,
        ]);

        $this->artisan('portal:merge-accounts', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $canonical->id]);
        $survivor = User::query()->findOrFail($legacy->id);
        $this->assertSame('student@local', $survivor->email);
        $this->assertNotNull($survivor->student()->first());
    }
}
