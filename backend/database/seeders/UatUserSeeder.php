<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UatUserSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('UAT demo users are not created in production.');
            return;
        }

        $roles = Role::query()->pluck('id', 'code');
        $password = Hash::make('demo12345');
        $users = [
            ['name' => 'Администратор UAT', 'email' => 'admin.uat@college-portal.local', 'role' => 'admin'],
            ['name' => 'Директор UAT', 'email' => 'director.uat@college-portal.local', 'role' => 'director'],
            ['name' => 'Заместитель директора UAT', 'email' => 'deputy.uat@college-portal.local', 'role' => 'deputy'],
            ['name' => 'Учебная часть UAT', 'email' => 'study.uat@college-portal.local', 'role' => 'study'],
            ['name' => 'Приемная комиссия UAT', 'email' => 'admission.uat@college-portal.local', 'role' => 'admission'],
            ['name' => 'Преподаватель UAT', 'email' => 'teacher1.uat@college-portal.local', 'role' => 'teacher'],
            ['name' => 'Студент UAT', 'email' => 'student1.uat@college-portal.local', 'role' => 'student'],
            ['name' => 'Сотрудник проходной UAT', 'email' => 'security.uat@college-portal.local', 'role' => 'security'],
        ];

        foreach ($users as $item) {
            $roleId = $roles[$item['role']] ?? null;
            $user = User::updateOrCreate(
                ['email' => $item['email']],
                [
                    'name' => $item['name'],
                    'role_id' => $roleId,
                    'password' => $password,
                    'is_active' => true,
                ]
            );

            if ($roleId) {
                $user->roles()->sync([$roleId => ['is_primary' => true]]);
            }
        }
    }
}
