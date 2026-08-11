<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\DigitalIdentity;
use App\Models\Employee;
use App\Models\Person;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Services\DigitalIdentityService;
use App\Services\HrService;
use App\Services\PersonService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Заводит рабочую учетную запись сотрудника целиком: человек, карточка кадров,
 * учетная запись с ролью и личный QR-пропуск.
 *
 * Раньше это собиралось из трех разных мест интерфейса, и получалось наполовину:
 * учетная запись без карточки сотрудника не видна отделу кадров, а карточка без
 * учетной записи не дает человеку войти и открыть свой пропуск.
 *
 * Персональные данные команда не хранит: ФИО и телефон приходят аргументами,
 * список конкретных людей лежит вне репозитория.
 */
class CreateStaffAccountCommand extends Command
{
    protected $signature = 'portal:staff-account
        {--email= : Адрес учетной записи, он же логин по email}
        {--role= : Код роли: director, hr, study_records, security и другие}
        {--last-name= : Фамилия}
        {--first-name= : Имя}
        {--middle-name= : Отчество}
        {--phone= : Телефон в любом написании, приводится к +7XXXXXXXXXX}
        {--position= : Должность; создается, если такой еще нет}
        {--department= : Подразделение; создается, если такого еще нет. Без флага подразделение существующей карточки не меняется}
        {--password= : Пароль; без него существующий пароль не меняется}
        {--username= : Логин; по умолчанию — локальная часть адреса}';

    protected $description = 'Создать или обновить учетную запись сотрудника вместе с карточкой кадров и пропуском.';

    public function __construct(
        private readonly DigitalIdentityService $digitalIdentities,
        private readonly PersonService $people,
        private readonly HrService $hr,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $email = trim((string) $this->option('email'));
        $roleCode = trim((string) $this->option('role'));
        $lastName = trim((string) $this->option('last-name'));
        $firstName = trim((string) $this->option('first-name'));

        if ($email === '' || $roleCode === '') {
            $this->error('Нужны --email и --role.');

            return self::FAILURE;
        }

        if ($lastName === '' && $firstName === '') {
            $this->error('Нужна хотя бы одна часть имени: --last-name или --first-name.');

            return self::FAILURE;
        }

        $role = Role::query()->where('code', $roleCode)->first();

        if (! $role) {
            $this->error("Роль {$roleCode} не найдена. Сначала выполните RoleSeeder.");

            return self::FAILURE;
        }

        $phone = $this->normalizePhone((string) $this->option('phone'));

        $generated = null;
        $result = DB::transaction(function () use ($email, $role, $lastName, $firstName, $phone, &$generated): array {
            $person = $this->resolvePerson($email, $lastName, $firstName, $phone);
            $employee = $this->resolveEmployee($person);
            $user = $this->resolveUser($email, $role, $person, $generated);

            return ['person' => $person, 'employee' => $employee, 'user' => $user];
        });

        $pass = $this->issuePassIfMissing($result['employee']);

        $this->info('Учетная запись готова.');
        $this->table(['Поле', 'Значение'], [
            ['ФИО', trim("{$result['person']->last_name} {$result['person']->first_name} {$result['person']->middle_name}") ?: '—'],
            ['Роль', $role->name],
            ['Вход по email', $result['user']->email],
            ['Вход по логину', $result['user']->username ?: '—'],
            ['Телефон', $result['person']->phone ?: '—'],
            ['Карточка кадров', "#{$result['employee']->id}"],
            ['QR-пропуск', $pass ? 'выпущен' : 'уже был'],
        ]);

        if ($generated !== null) {
            $this->warn("Выдан разовый пароль: {$generated}");
        }

        return self::SUCCESS;
    }

    /**
     * Person ищется по учетной записи, затем по телефону, и только потом
     * создается. ФИО правится через PersonService: карточки сотрудника и
     * преподавателя получают его зеркалом, и второй источник правды тут не нужен.
     */
    private function resolvePerson(string $email, string $lastName, string $firstName, ?string $phone): Person
    {
        $person = User::query()->where('email', $email)->first()?->person
            ?: Person::query()->where('email', $email)->first()
            ?: $this->personByPhone($phone);

        $attributes = array_filter([
            'last_name' => $lastName,
            'first_name' => $firstName,
            'middle_name' => trim((string) $this->option('middle-name')),
            'phone' => $phone,
        ], fn (?string $value): bool => $value !== null && $value !== '');

        if (! $person) {
            // last_name и first_name в таблице не допускают NULL, поэтому
            // недостающая часть имени пишется пустой строкой: карточка сразу
            // видна как незаполненная и правится в интерфейсе.
            return Person::create($attributes + [
                'last_name' => $lastName,
                'first_name' => $firstName,
                'email' => $email,
                'status' => 'active',
            ]);
        }

        return $this->people->updateSharedData($person, $attributes);
    }

    /**
     * Телефон в базе записан по-разному: у карточек, заведенных вручную, он лежит
     * без плюса, а команда приводит номер к виду +7XXXXXXXXXX. Сравнение строк
     * один в один эти записи не находило, и на один и тот же живой человек
     * появлялся второй Person со второй карточкой кадров. Сравниваем по последним
     * десяти цифрам — они одинаковы при любом написании.
     */
    private function personByPhone(?string $phone): ?Person
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $tail = substr($digits, -10);

        return strlen($tail) < 10 ? null : Person::query()->where('phone', 'like', '%'.$tail)->orderBy('id')->first();
    }

    /**
     * Существующую карточку команда не переписывает: у заведенной вручную могут
     * стоять свой тип занятости, дата приема и признак преподавателя, и повторный
     * запуск не должен их обнулять. Меняются только явно переданные должность и
     * подразделение.
     */
    private function resolveEmployee(Person $person): Employee
    {
        $employee = Employee::query()->firstOrNew(['person_id' => $person->id]);

        if (! $employee->exists) {
            $employee->fill([
                // Номер выдается тем же правилом, что и в кадровом модуле:
                // два независимых способа нумерации разошлись бы.
                'employee_number' => $this->hr->nextEmployeeNumber(),
                'status' => 'active',
                'employment_type' => 'full_time',
                'hired_at' => now()->toDateString(),
                'workload_rate' => 1,
                'is_teacher' => false,
            ]);
        }

        if ($position = trim((string) $this->option('position'))) {
            $employee->primary_position_id = $this->resolvePosition($position)->id;
        }

        if ($department = trim((string) $this->option('department'))) {
            $employee->primary_department_id = $this->resolveDepartment($department)->id;
        }

        $employee->save();

        return $employee;
    }

    private function resolveUser(string $email, Role $role, Person $person, ?string &$generated): User
    {
        $password = (string) $this->option('password');
        $username = trim((string) $this->option('username')) ?: Str::before($email, '@');
        $name = trim("{$person->last_name} {$person->first_name} {$person->middle_name}");

        $user = User::query()->where('email', $email)->first() ?? new User(['email' => $email]);

        // Новой учетной записи пароль нужен обязательно: колонка не допускает NULL,
        // а войти без пароля нельзя. Если его не задали, он выдается разово
        // и показывается в выводе — повторно его уже негде посмотреть.
        if ($password === '' && ! $user->exists) {
            $password = $generated = Str::password(14, true, true, false, false);
        }
        $user->fill([
            'name' => $name,
            'role_id' => $role->id,
            'is_active' => true,
            'person_type' => 'person',
            'person_id' => $person->id,
        ]);
        $user->username = $user->username ?: $username;

        if ($password !== '') {
            $user->password = Hash::make($password);
            // Пароль знает не только владелец учётной записи — он показан в выводе
            // команды. Значит, после входа портал предложит завести свой. Повторный
            // вызов без `--password` пароль не трогает и отметку тоже.
            $user->must_change_password = true;
        }

        $user->save();
        $user->roles()->sync([$role->id => ['is_primary' => true]]);

        return $user;
    }

    /**
     * Пропуск выпускается один раз: повторный вызов команды не должен отзывать
     * уже выданный сотруднику QR, иначе распечатанный пропуск перестанет
     * открывать турникет.
     */
    private function issuePassIfMissing(Employee $employee): bool
    {
        $hasPass = DigitalIdentity::query()
            ->where('entity_type', 'employee')
            ->where('entity_id', $employee->id)
            ->where('status', DigitalIdentity::STATUS_ACTIVE)
            ->exists();

        if ($hasPass) {
            return false;
        }

        $this->digitalIdentities->issue('employee', $employee->id, null, request(), 'digital_identity', 'issue_qr_with_account');

        return true;
    }

    private function resolvePosition(string $name): Position
    {
        return Position::query()->firstOrCreate(
            ['name' => $name],
            ['code' => $this->code($name), 'category' => 'administrative', 'is_teaching_position' => false, 'is_active' => true]
        );
    }

    private function resolveDepartment(string $name): Department
    {
        return Department::query()->firstOrCreate(
            ['name' => $name],
            ['code' => $this->code($name), 'type' => 'administrative', 'is_active' => true]
        );
    }

    private function code(string $name): string
    {
        return Str::upper(Str::slug($name, '_')) ?: Str::upper(Str::random(8));
    }

    private function normalizePhone(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (preg_match('/^(?:7|8)(\d{10})$/', $digits, $matches) || preg_match('/^(\d{10})$/', $digits, $matches)) {
            return "+7{$matches[1]}";
        }

        return $digits === '' ? null : $digits;
    }
}
