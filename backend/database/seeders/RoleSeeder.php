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
            ['code' => 'academic_office', 'name' => 'Учебная часть', 'description' => 'Ведение студентов, групп, расписания и журнала.'],
            ['code' => 'teacher', 'name' => 'Преподаватель', 'description' => 'Работа с расписанием, посещаемостью и оценками.'],
            ['code' => 'curator', 'name' => 'Куратор группы', 'description' => 'Сопровождение закрепленной учебной группы.'],
            ['code' => 'student', 'name' => 'Студент', 'description' => 'Просмотр личного расписания, посещаемости и оценок.'],
            ['code' => 'director', 'name' => 'Руководитель', 'description' => 'Просмотр отчетов и управленческих сводок.'],
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

        Role::where('code', 'admin')->first()?->permissions()->sync(Permission::query()->pluck('id'));
        Role::where('code', 'academic_office')->first()?->permissions()->sync(
            Permission::whereIn('code', ['manage_dictionaries', 'manage_schedule', 'manage_journal', 'view_reports'])->pluck('id')
        );
        Role::where('code', 'teacher')->first()?->permissions()->sync(
            Permission::whereIn('code', ['manage_journal', 'view_own_data'])->pluck('id')
        );
        Role::where('code', 'curator')->first()?->permissions()->sync(
            Permission::whereIn('code', ['manage_journal', 'view_reports', 'view_own_data'])->pluck('id')
        );
        Role::where('code', 'student')->first()?->permissions()->sync(
            Permission::whereIn('code', ['view_own_data'])->pluck('id')
        );
        Role::where('code', 'director')->first()?->permissions()->sync(
            Permission::whereIn('code', ['view_reports'])->pluck('id')
        );
    }
}
