<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Person;
use App\Models\Teacher;
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
            ['name' => 'Администратор UAT', 'email' => 'admin.uat@college-portal.local', 'username' => 'admin', 'role' => 'admin'],
            ['name' => 'Директор UAT', 'email' => 'director.uat@college-portal.local', 'username' => 'director', 'role' => 'director'],
            ['name' => 'Заместитель директора UAT', 'email' => 'deputy.uat@college-portal.local', 'username' => 'deputy', 'role' => 'deputy'],
            ['name' => 'Учебная часть UAT', 'email' => 'study.uat@college-portal.local', 'username' => 'study', 'role' => 'study'],
            ['name' => 'Приемная комиссия UAT', 'email' => 'admission.uat@college-portal.local', 'username' => 'admission', 'role' => 'admission'],
            ['name' => 'Преподаватель UAT', 'email' => 'teacher1.uat@college-portal.local', 'username' => 'teacher', 'role' => 'teacher'],
            ['name' => 'Студент UAT', 'email' => 'student1.uat@college-portal.local', 'username' => 'student', 'role' => 'student'],
            ['name' => 'Сотрудник проходной UAT', 'email' => 'security.uat@college-portal.local', 'username' => 'security', 'role' => 'security'],
        ];

        foreach ($users as $item) {
            $roleId = $roles[$item['role']] ?? null;
            $user = User::updateOrCreate(
                ['email' => $item['email']],
                [
                    'name' => $item['name'],
                    'username' => $item['username'],
                    'role_id' => $roleId,
                    'password' => $password,
                    'is_active' => true,
                ]
            );

            if ($roleId) {
                $user->roles()->sync([$roleId => ['is_primary' => true]]);
            }
        }

        $teacherUser = User::query()->where('email', 'teacher1.uat@college-portal.local')->firstOrFail();
        $person = Person::query()->firstOrCreate(
            ['email' => $teacherUser->email],
            ['last_name' => 'Преподаватель', 'first_name' => 'UAT', 'middle_name' => null, 'status' => 'active']
        );
        $teacher = Teacher::query()->updateOrCreate(
            ['person_id' => $person->id],
            [
                'user_id' => $teacherUser->id,
                'last_name' => $person->last_name,
                'first_name' => $person->first_name,
                'middle_name' => $person->middle_name,
                'email' => $teacherUser->email,
                'position' => 'Преподаватель',
                'department' => 'UAT',
                'is_active' => true,
            ]
        );

        $teacherUser->update(['person_type' => 'person', 'person_id' => $person->id]);
        $teacher->update(['user_id' => $teacherUser->id]);
    }
}
