<?php

namespace Database\Seeders;

use App\Models\Group;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Один набор служебных учетных записей стенда — по одной на роль.
 *
 * До 10.08.2026 наборов было два: смоук-пользователи из DemoDataSeeder с паролем
 * из DEMO_USER_PASSWORD и параллельный набор с приставкой UAT и собственным
 * паролем. Роль директора существовала дважды с разными паролями, и на
 * демонстрации приходилось помнить, какой из них сейчас нужен. Теперь адрес
 * один, пароль один, приставки нет.
 *
 * Имя существующей учетной записи не перезаписывается: teacher@local и
 * student@local получают в DemoDataSeeder настоящие демонстрационные ФИО вместе
 * с расписанием и журналом, и затирать их обезличенным «Преподаватель» нельзя.
 */
class PortalUserSeeder extends Seeder
{
    public const DOMAIN = 'local';

    /** @return array<int, array{name:string, username:string, role:string}> */
    public static function accounts(): array
    {
        return [
            ['name' => 'Администратор', 'username' => 'admin', 'role' => 'admin'],
            ['name' => 'Директор', 'username' => 'director', 'role' => 'director'],
            ['name' => 'Заместитель директора', 'username' => 'deputy', 'role' => 'deputy'],
            ['name' => 'Учебная часть 1', 'username' => 'study', 'role' => 'study'],
            ['name' => 'Учебная часть 2', 'username' => 'study.records', 'role' => 'study_records'],
            ['name' => 'Приемная комиссия', 'username' => 'admission', 'role' => 'admission'],
            ['name' => 'Отдел кадров', 'username' => 'hr', 'role' => 'hr'],
            ['name' => 'Преподаватель', 'username' => 'teacher', 'role' => 'teacher'],
            // Куратор появился в наборе 11.08.2026: роль существовала и держала
            // права мобильных кабинетов, а учётной записи под неё не было, и
            // обход прав `check-role-access.sh` эту роль просто не проверял.
            ['name' => 'Куратор', 'username' => 'curator', 'role' => 'curator'],
            ['name' => 'Студент', 'username' => 'student', 'role' => 'student'],
            ['name' => 'Сотрудник проходной', 'username' => 'security', 'role' => 'security'],
        ];
    }

    public static function email(string $username): string
    {
        return $username.'@'.self::DOMAIN;
    }

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('Служебные учетные записи стенда в production не создаются.');

            return;
        }

        $roles = Role::query()->pluck('id', 'code');
        // Пустое значение в .env — это пустая строка, а не «не задано»,
        // поэтому значение по умолчанию берется через ?:, а не вторым аргументом env().
        $password = Hash::make(env('DEMO_USER_PASSWORD') ?: 'test1234');

        foreach (self::accounts() as $item) {
            $email = self::email($item['username']);
            $roleId = $roles[$item['role']] ?? null;

            $user = User::query()->where('email', $email)->first() ?? new User([
                'email' => $email,
                'name' => $item['name'],
                'username' => $item['username'],
            ]);

            $user->fill(['role_id' => $roleId, 'password' => $password, 'is_active' => true]);
            $user->username = $user->username ?: $item['username'];
            $user->save();

            if ($roleId) {
                $user->roles()->sync([$roleId => ['is_primary' => true]]);
            }
        }

        $this->linkTeacher();
        $this->linkStudent();
    }

    /**
     * Учетная запись преподавателя без профиля бесполезна: журнал, нагрузка и
     * личный пропуск разрешаются через Teacher. DemoDataSeeder связывает
     * teacher@local с настоящим демонстрационным преподавателем; здесь связь
     * создается только тогда, когда демо-данных нет.
     */
    private function linkTeacher(): void
    {
        $user = User::query()->where('email', self::email('teacher'))->first();

        if (! $user || Teacher::query()->where('user_id', $user->id)->exists()) {
            return;
        }

        $person = Person::query()->firstOrCreate(
            ['email' => $user->email],
            ['last_name' => 'Преподаватель', 'first_name' => 'Стенда', 'middle_name' => null, 'status' => 'active']
        );

        $teacher = Teacher::query()->updateOrCreate(
            ['person_id' => $person->id],
            [
                'user_id' => $user->id,
                'last_name' => $person->last_name,
                'first_name' => $person->first_name,
                'middle_name' => $person->middle_name,
                'email' => $user->email,
                'position' => 'Преподаватель',
                'department' => 'Стенд',
                'is_active' => true,
            ]
        );

        $user->update(['person_type' => 'person', 'person_id' => $person->id]);
        $teacher->update(['user_id' => $user->id]);
    }

    /**
     * Тот же контур для студента: без цепочки User -> Person -> Student личный
     * кабинет, личный QR и журнал группы не разрешаются ни во что.
     *
     * Группа не создается: students.group_id не допускает NULL, а выдуманная
     * группа-заглушка потом путается с настоящими. Если групп еще нет, связь
     * появится после DemoDataSeeder.
     */
    private function linkStudent(): void
    {
        $user = User::query()->where('email', self::email('student'))->first();

        if (! $user || Student::query()->where('user_id', $user->id)->exists()) {
            return;
        }

        $group = Group::query()->orderBy('id')->first();

        if (! $group) {
            return;
        }

        $person = Person::query()->firstOrCreate(
            ['email' => $user->email],
            ['last_name' => 'Студент', 'first_name' => 'Стенда', 'middle_name' => null, 'status' => 'active']
        );

        $student = Student::query()->updateOrCreate(
            ['person_id' => $person->id],
            [
                'user_id' => $user->id,
                'group_id' => $group->id,
                'course' => $group->course,
                'last_name' => $person->last_name,
                'first_name' => $person->first_name,
                'middle_name' => $person->middle_name,
                'email' => $user->email,
                'status' => 'active',
            ]
        );

        $user->update(['person_type' => 'person', 'person_id' => $person->id]);
        $student->update(['user_id' => $user->id]);
    }
}
