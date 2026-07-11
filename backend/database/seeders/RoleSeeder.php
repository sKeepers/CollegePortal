<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['code' => 'admin', 'name' => 'Администратор', 'description' => 'Полный доступ к системе.'],
            ['code' => 'director', 'name' => 'Директор', 'description' => 'Управленческий просмотр отчетов и сводок.'],
            ['code' => 'deputy', 'name' => 'Заместитель директора', 'description' => 'Контроль учебного процесса, отчетов и справочников.'],
            ['code' => 'study', 'name' => 'Учебная часть', 'description' => 'Ведение студентов, групп, расписания и журнала.'],
            ['code' => 'admission', 'name' => 'Приемная комиссия', 'description' => 'Работа с абитуриентами и приемными кампаниями.'],
            ['code' => 'teacher', 'name' => 'Преподаватель', 'description' => 'Работа со своим расписанием, журналом и нагрузкой.'],
            ['code' => 'student', 'name' => 'Студент', 'description' => 'Просмотр личного кабинета, QR, расписания и оценок.'],
            ['code' => 'security', 'name' => 'Сотрудник проходной', 'description' => 'Сканирование QR и отчеты проходной.'],
            ['code' => 'academic_office', 'name' => 'Учебная часть (legacy)', 'description' => 'Legacy-роль для совместимости.'],
            ['code' => 'curator', 'name' => 'Куратор группы', 'description' => 'Сопровождение закрепленной учебной группы.'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['code' => $role['code']], $role);
        }

        foreach ($this->permissions() as $permission) {
            Permission::updateOrCreate(
                ['code' => $permission['code']],
                $permission + ['system' => true, 'active' => true]
            );
        }

        $all = Permission::query()->pluck('id');
        $this->syncPermissions('admin', $all);
        $this->syncPermissions('director', $this->ids($this->directorPermissions()));
        $this->syncPermissions('deputy', $this->ids($this->academicEditorPermissions()));
        $this->syncPermissions('study', $this->ids($this->academicEditorPermissions()));
        $this->syncPermissions('academic_office', $this->ids($this->academicEditorPermissions()));
        $this->syncPermissions('admission', $this->ids($this->admissionPermissions()));
        $this->syncPermissions('teacher', $this->ids($this->teacherPermissions()));
        $this->syncPermissions('student', $this->ids($this->studentPermissions()));
        $this->syncPermissions('security', $this->ids($this->securityPermissions()));
        $this->syncPermissions('curator', $this->ids(array_values(array_unique(array_merge($this->teacherPermissions(), ['students.view', 'groups.view', 'attendance.reports'])))));
    }

    private function permissions(): array
    {
        return [
            ['module' => 'Dashboard', 'code' => 'dashboard.view', 'name' => 'Просмотр Dashboard', 'description' => 'Открытие рабочего стола.'],
            ['module' => 'Identity', 'code' => 'people.view', 'name' => 'Person: просмотр', 'description' => 'Просмотр единого реестра физических лиц.'],
            ['module' => 'Identity', 'code' => 'people.create', 'name' => 'Person: создание', 'description' => 'Создание Person вручную на будущих этапах.'],
            ['module' => 'Identity', 'code' => 'people.update', 'name' => 'Person: изменение', 'description' => 'Редактирование общих данных Person на будущих этапах.'],
            ['module' => 'Identity', 'code' => 'people.link', 'name' => 'Person: связывание', 'description' => 'Связывание профилей с Person.'],
            ['module' => 'Identity', 'code' => 'people.merge', 'name' => 'Person: объединение', 'description' => 'Будущая операция объединения Person.'],
            ['module' => 'Students', 'code' => 'students.view', 'name' => 'Студенты: просмотр', 'description' => 'Просмотр списка и карточек студентов.'],
            ['module' => 'Students', 'code' => 'students.create', 'name' => 'Студенты: создание', 'description' => 'Создание студентов.'],
            ['module' => 'Students', 'code' => 'students.update', 'name' => 'Студенты: изменение', 'description' => 'Редактирование студентов, импорт и фото.'],
            ['module' => 'Students', 'code' => 'students.delete', 'name' => 'Студенты: удаление', 'description' => 'Удаление студентов.'],
            ['module' => 'Groups', 'code' => 'groups.view', 'name' => 'Группы: просмотр', 'description' => 'Просмотр групп.'],
            ['module' => 'Groups', 'code' => 'groups.create', 'name' => 'Группы: создание', 'description' => 'Создание групп.'],
            ['module' => 'Groups', 'code' => 'groups.update', 'name' => 'Группы: изменение', 'description' => 'Редактирование групп и импорт.'],
            ['module' => 'Groups', 'code' => 'groups.delete', 'name' => 'Группы: удаление', 'description' => 'Удаление групп.'],
            ['module' => 'Teachers', 'code' => 'teachers.view', 'name' => 'Преподаватели: просмотр', 'description' => 'Просмотр преподавателей.'],
            ['module' => 'Teachers', 'code' => 'teachers.create', 'name' => 'Преподаватели: создание', 'description' => 'Создание преподавателей.'],
            ['module' => 'Teachers', 'code' => 'teachers.update', 'name' => 'Преподаватели: изменение', 'description' => 'Редактирование преподавателей, импорт и фото.'],
            ['module' => 'Teachers', 'code' => 'teachers.delete', 'name' => 'Преподаватели: удаление', 'description' => 'Удаление преподавателей.'],
            ['module' => 'Subjects', 'code' => 'subjects.view', 'name' => 'Дисциплины: просмотр', 'description' => 'Просмотр дисциплин.'],
            ['module' => 'Subjects', 'code' => 'subjects.create', 'name' => 'Дисциплины: создание', 'description' => 'Создание дисциплин.'],
            ['module' => 'Subjects', 'code' => 'subjects.update', 'name' => 'Дисциплины: изменение', 'description' => 'Редактирование дисциплин и импорт.'],
            ['module' => 'Subjects', 'code' => 'subjects.delete', 'name' => 'Дисциплины: удаление', 'description' => 'Удаление дисциплин.'],
            ['module' => 'Classrooms', 'code' => 'classrooms.view', 'name' => 'Аудитории: просмотр', 'description' => 'Просмотр аудиторий.'],
            ['module' => 'Classrooms', 'code' => 'classrooms.create', 'name' => 'Аудитории: создание', 'description' => 'Создание аудиторий.'],
            ['module' => 'Classrooms', 'code' => 'classrooms.update', 'name' => 'Аудитории: изменение', 'description' => 'Редактирование аудиторий и импорт.'],
            ['module' => 'Classrooms', 'code' => 'classrooms.delete', 'name' => 'Аудитории: удаление', 'description' => 'Удаление аудиторий.'],
            ['module' => 'Schedule', 'code' => 'schedule.view', 'name' => 'Расписание: просмотр', 'description' => 'Просмотр расписания.'],
            ['module' => 'Schedule', 'code' => 'schedule.update', 'name' => 'Расписание: изменение', 'description' => 'Создание, изменение и удаление занятий.'],
            ['module' => 'Journal', 'code' => 'journal.view', 'name' => 'Журнал: просмотр', 'description' => 'Просмотр журнала.'],
            ['module' => 'Journal', 'code' => 'journal.edit', 'name' => 'Журнал: ведение', 'description' => 'Внесение посещаемости и оценок.'],
            ['module' => 'Journal', 'code' => 'journal.export', 'name' => 'Журнал: экспорт', 'description' => 'Экспорт отчетов журнала.'],
            ['module' => 'Attendance', 'code' => 'attendance.view', 'name' => 'Посещаемость: просмотр', 'description' => 'Просмотр аналитики посещаемости.'],
            ['module' => 'Attendance', 'code' => 'attendance.reports', 'name' => 'Посещаемость: отчеты', 'description' => 'Исторические отчеты посещаемости.'],
            ['module' => 'Admissions', 'code' => 'admissions.view', 'name' => 'Приемная комиссия: просмотр', 'description' => 'Просмотр заявлений.'],
            ['module' => 'Admissions', 'code' => 'admissions.edit', 'name' => 'Приемная комиссия: ведение', 'description' => 'Создание, изменение, импорт и зачисление заявлений.'],
            ['module' => 'Curricula', 'code' => 'curricula.view', 'name' => 'Учебные планы: просмотр', 'description' => 'Просмотр учебных планов.'],
            ['module' => 'Curricula', 'code' => 'curricula.edit', 'name' => 'Учебные планы: ведение', 'description' => 'Создание, изменение и импорт учебных планов.'],
            ['module' => 'Teaching Load', 'code' => 'teachingload.view', 'name' => 'Нагрузка: просмотр', 'description' => 'Просмотр нагрузки преподавателей.'],
            ['module' => 'Teaching Load', 'code' => 'teachingload.edit', 'name' => 'Нагрузка: ведение', 'description' => 'Создание, изменение и импорт нагрузки.'],
            ['module' => 'Exams', 'code' => 'exams.view', 'name' => 'Экзамены: просмотр', 'description' => 'Просмотр экзаменов и ГИА.'],
            ['module' => 'Exams', 'code' => 'exams.edit', 'name' => 'Экзамены: ведение', 'description' => 'Создание, изменение и импорт экзаменов.'],
            ['module' => 'Graduation', 'code' => 'graduation.view', 'name' => 'Выпуск: просмотр', 'description' => 'Просмотр выпускников и дипломов.'],
            ['module' => 'Graduation', 'code' => 'graduation.edit', 'name' => 'Выпуск: ведение', 'description' => 'Создание и изменение выпускников, дипломов и приложений.'],
            ['module' => 'FRDO', 'code' => 'frdo.view', 'name' => 'ФРДО: просмотр', 'description' => 'Просмотр пакетов ФРДО.'],
            ['module' => 'FRDO', 'code' => 'frdo.export', 'name' => 'ФРДО: экспорт', 'description' => 'Подготовка, проверка и экспорт пакетов ФРДО.'],
            ['module' => 'FIS', 'code' => 'fis.view', 'name' => 'ФИС: просмотр', 'description' => 'Просмотр пакетов ФИС.'],
            ['module' => 'FIS', 'code' => 'fis.export', 'name' => 'ФИС: экспорт', 'description' => 'Подготовка, проверка и экспорт пакетов ФИС.'],
            ['module' => 'Identity', 'code' => 'gate.scan', 'name' => 'Проходная: сканирование', 'description' => 'Сканирование QR на проходной.'],
            ['module' => 'Identity', 'code' => 'gate.reports', 'name' => 'Проходная: отчеты', 'description' => 'Просмотр событий и отчетов проходной.'],
            ['module' => 'Identity', 'code' => 'digitalpasses.manage', 'name' => 'Цифровые пропуска: управление', 'description' => 'Выпуск, отзыв и просмотр QR-пропусков.'],
            ['module' => 'System', 'code' => 'users.manage', 'name' => 'Пользователи: управление', 'description' => 'Управление пользователями.'],
            ['module' => 'System', 'code' => 'roles.manage', 'name' => 'Роли: управление', 'description' => 'Управление ролями.'],
            ['module' => 'System', 'code' => 'permissions.manage', 'name' => 'Разрешения: управление', 'description' => 'Управление матрицей разрешений.'],
            ['module' => 'System', 'code' => 'settings.manage', 'name' => 'Настройки: управление', 'description' => 'Управление настройками колледжа.'],
            ['module' => 'System', 'code' => 'audit.view', 'name' => 'Аудит: просмотр', 'description' => 'Просмотр журнала аудита.'],
            ['module' => 'System', 'code' => 'reference.manage', 'name' => 'Справочники: управление', 'description' => 'Управление нормативно-справочной информацией.'],
            ['module' => 'System', 'code' => 'import.manage', 'name' => 'Импорт: управление', 'description' => 'Универсальный импорт и демо-данные.'],
            ['module' => 'System', 'code' => 'ui.foundation.view', 'name' => 'UI Foundation: просмотр', 'description' => 'Просмотр витрины UI-компонентов.'],
            ['module' => 'Mobile', 'code' => 'mobile.student.view', 'name' => 'Мобильный кабинет студента', 'description' => 'Доступ к мобильному кабинету студента.'],
            ['module' => 'Mobile', 'code' => 'mobile.student.pass', 'name' => 'Мобильный QR студента', 'description' => 'Просмотр мобильного QR-пропуска.'],
            ['module' => 'Legacy', 'code' => 'view_own_data', 'name' => 'Просмотр личных данных', 'description' => 'Legacy permission для совместимости.'],
            ['module' => 'Legacy', 'code' => 'view_reports', 'name' => 'Просмотр отчетов', 'description' => 'Legacy permission для совместимости.'],
            ['module' => 'Legacy', 'code' => 'manage_users', 'name' => 'Управление пользователями', 'description' => 'Legacy permission для совместимости.'],
            ['module' => 'Legacy', 'code' => 'manage_dictionaries', 'name' => 'Управление справочниками', 'description' => 'Legacy permission для совместимости.'],
            ['module' => 'Legacy', 'code' => 'manage_schedule', 'name' => 'Управление расписанием', 'description' => 'Legacy permission для совместимости.'],
            ['module' => 'Legacy', 'code' => 'manage_journal', 'name' => 'Ведение журнала', 'description' => 'Legacy permission для совместимости.'],
        ];
    }

    private function directorPermissions(): array
    {
        return [
            'dashboard.view', 'people.view', 'students.view', 'groups.view', 'teachers.view', 'subjects.view', 'classrooms.view',
            'schedule.view', 'journal.view', 'journal.export', 'attendance.view', 'attendance.reports',
            'admissions.view', 'curricula.view', 'teachingload.view', 'exams.view', 'graduation.view',
            'frdo.view', 'fis.view', 'gate.reports', 'audit.view', 'reference.manage', 'view_reports',
        ];
    }

    private function academicEditorPermissions(): array
    {
        return [
            'dashboard.view', 'people.view', 'students.view', 'students.create', 'students.update', 'students.delete',
            'groups.view', 'groups.create', 'groups.update', 'groups.delete',
            'teachers.view', 'teachers.create', 'teachers.update', 'teachers.delete',
            'subjects.view', 'subjects.create', 'subjects.update', 'subjects.delete',
            'classrooms.view', 'classrooms.create', 'classrooms.update', 'classrooms.delete',
            'schedule.view', 'schedule.update', 'journal.view', 'journal.edit', 'journal.export',
            'attendance.view', 'attendance.reports', 'curricula.view', 'curricula.edit',
            'teachingload.view', 'teachingload.edit', 'exams.view', 'exams.edit',
            'graduation.view', 'graduation.edit', 'people.update', 'people.link', 'frdo.view', 'fis.view', 'reference.manage', 'import.manage',
            'manage_dictionaries', 'manage_schedule', 'manage_journal', 'view_reports',
        ];
    }

    private function admissionPermissions(): array
    {
        return ['dashboard.view', 'people.view', 'admissions.view', 'admissions.edit', 'students.view', 'groups.view', 'reference.manage', 'import.manage', 'view_reports'];
    }

    private function teacherPermissions(): array
    {
        return ['dashboard.view', 'schedule.view', 'journal.view', 'journal.edit', 'journal.export', 'attendance.view', 'teachingload.view', 'exams.view', 'digitalpasses.manage', 'view_own_data', 'manage_journal'];
    }

    private function studentPermissions(): array
    {
        return ['dashboard.view', 'schedule.view', 'journal.view', 'attendance.view', 'mobile.student.view', 'mobile.student.pass', 'view_own_data'];
    }

    private function securityPermissions(): array
    {
        return ['dashboard.view', 'gate.scan', 'gate.reports', 'digitalpasses.manage', 'attendance.view', 'attendance.reports', 'view_reports'];
    }

    private function ids(array $codes)
    {
        return Permission::whereIn('code', $codes)->pluck('id');
    }

    private function syncPermissions(string $roleCode, $permissionIds): void
    {
        Role::where('code', $roleCode)->first()?->permissions()->sync($permissionIds);
    }
}
