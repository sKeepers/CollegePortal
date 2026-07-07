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
            ['code' => 'teacher', 'name' => 'Преподаватель', 'description' => 'Работа с расписанием, посещаемостью и оценками.'],
            ['code' => 'student', 'name' => 'Студент', 'description' => 'Просмотр личного расписания, посещаемости и оценок.'],
            ['code' => 'security', 'name' => 'Сотрудник проходной', 'description' => 'Работа с проходной и событиями доступа.'],
            ['code' => 'academic_office', 'name' => 'Учебная часть (legacy)', 'description' => 'Legacy-роль для совместимости.'],
            ['code' => 'curator', 'name' => 'Куратор группы', 'description' => 'Сопровождение закрепленной учебной группы.'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['code' => $role['code']], $role);
        }

        $permissions = [
            ['code' => 'manage_users', 'name' => 'Управление пользователями'],
            ['code' => 'manage_dictionaries', 'name' => 'Управление справочниками'],
            ['code' => 'manage_schedule', 'name' => 'Управление расписанием'],
            ['code' => 'manage_journal', 'name' => 'Ведение журнала'],
            ['code' => 'view_reports', 'name' => 'Просмотр отчетов'],
            ['code' => 'view_own_data', 'name' => 'Просмотр личных данных'],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(['code' => $permission['code']], $permission);
        }

        $this->syncPermissions('admin', Permission::query()->pluck('id'));
        $this->syncPermissions('director', Permission::whereIn('code', ['view_reports'])->pluck('id'));
        $this->syncPermissions('deputy', Permission::whereIn('code', ['manage_dictionaries', 'manage_schedule', 'manage_journal', 'view_reports'])->pluck('id'));
        $this->syncPermissions('study', Permission::whereIn('code', ['manage_dictionaries', 'manage_schedule', 'manage_journal', 'view_reports'])->pluck('id'));
        $this->syncPermissions('admission', Permission::whereIn('code', ['manage_dictionaries', 'view_reports'])->pluck('id'));
        $this->syncPermissions('teacher', Permission::whereIn('code', ['manage_journal', 'view_own_data'])->pluck('id'));
        $this->syncPermissions('student', Permission::whereIn('code', ['view_own_data'])->pluck('id'));
        $this->syncPermissions('security', Permission::whereIn('code', ['manage_dictionaries', 'view_reports'])->pluck('id'));
        $this->syncPermissions('academic_office', Permission::whereIn('code', ['manage_dictionaries', 'manage_schedule', 'manage_journal', 'view_reports'])->pluck('id'));
        $this->syncPermissions('curator', Permission::whereIn('code', ['manage_journal', 'view_reports', 'view_own_data'])->pluck('id'));
    }

    private function syncPermissions(string $roleCode, $permissionIds): void
    {
        Role::where('code', $roleCode)->first()?->permissions()->sync($permissionIds);
    }
}
