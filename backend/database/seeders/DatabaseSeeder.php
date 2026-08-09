<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            ReferenceDataSeeder::class,
            AdmissionReferenceSeeder::class,
            DemoDataSeeder::class,
            // Идет после демо-данных: DemoDataSeeder дает teacher@local и
            // student@local настоящие ФИО с расписанием и журналом, а
            // PortalUserSeeder только досоздает недостающие роли и выравнивает
            // пароль, не трогая уже заполненные имена.
            PortalUserSeeder::class,
        ]);
    }
}
