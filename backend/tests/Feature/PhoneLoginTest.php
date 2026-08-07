<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Role;
use App\Models\Student;
use App\Services\AccountProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Телефон в базе записан по-разному: импорт оставляет цифры, формы — с плюсом.
 * Вход должен принимать любое написание, иначе заведенный импортом человек не
 * попадет в портал вообще.
 */
class PhoneLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_by_phone_accepts_every_written_form(): void
    {
        $student = $this->studentWithAccount('79331785695');

        foreach (['+79331785695', '89331785695', '79331785695', '+7 (933) 178-56-95'] as $login) {
            $this->postJson('/api/auth/login', ['login' => $login, 'password' => 'secret-pass'])
                ->assertOk()
                ->assertJsonPath('user.id', $student->fresh()->user_id);
        }
    }

    public function test_provisioned_login_is_the_phone_in_one_form(): void
    {
        Role::create(['name' => 'Студент', 'code' => 'student']);
        $student = $this->student('89331785695');

        $account = app(AccountProvisioningService::class)->provision($student);

        $this->assertSame('+79331785695', $account->login);
    }

    public function test_person_without_phone_gets_surname_with_initials(): void
    {
        Role::create(['name' => 'Студент', 'code' => 'student']);
        $student = Student::create([
            'group_id' => $this->group()->id,
            'last_name' => 'Альгашова',
            'first_name' => 'Милена',
            'middle_name' => 'Владимировна',
            'status' => 'active',
        ]);

        $account = app(AccountProvisioningService::class)->provision($student);

        $this->assertSame('algashova.mv', $account->login);
    }

    private function group(): Group
    {
        return Group::firstOrCreate(
            ['name' => 'ИСП-101'],
            ['specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]
        );
    }

    private function student(string $phone): Student
    {
        return Student::create([
            'group_id' => $this->group()->id,
            'last_name' => 'Иванов',
            'first_name' => 'Дмитрий',
            'middle_name' => 'Сергеевич',
            'status' => 'active',
            'phone' => $phone,
        ]);
    }

    private function studentWithAccount(string $phone): Student
    {
        Role::create(['name' => 'Студент', 'code' => 'student']);
        $student = $this->student($phone);
        app(AccountProvisioningService::class)->provision($student);
        $student->refresh();
        $student->user->forceFill(['password' => 'secret-pass'])->save();

        return $student;
    }
}
