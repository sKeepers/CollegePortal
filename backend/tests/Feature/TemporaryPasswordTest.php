<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Auth\TemporaryPassword;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Временный пароль: длина и срок годности.
 *
 * До 23.08.2026 портал выдавал пять цифр — 90 000 вариантов — и срока у них не
 * было. Отметка `must_change_password` рассчитана на первый вход, но у записи,
 * которой никто не пользовался, первый вход не наступает никогда: временное
 * становилось постоянным. Записи при этом заводятся пачкой на весь контингент,
 * а портал смотрит наружу.
 */
class TemporaryPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_issued_password_is_long_enough_and_free_of_lookalikes(): void
    {
        $password = TemporaryPassword::generate();

        $this->assertSame(8, strlen($password));
        $this->assertDoesNotMatchRegularExpression(
            '/[0Oo1lI]/',
            $password,
            'Похожие знаки путают при чтении вслух: пароль диктуют и вводят с листка.',
        );
        $this->assertNotSame(TemporaryPassword::generate(), TemporaryPassword::generate());
    }

    public function test_a_reset_password_carries_a_term(): void
    {
        $this->seed(RoleSeeder::class);
        // Сброс делается профилю, а не учётной записи: администратор ищет
        // человека, а не строку в таблице пользователей.
        $person = Person::query()->create(['last_name' => 'Забывчивый', 'first_name' => 'Проверочный', 'status' => 'active']);
        $employee = Employee::query()->create([
            'person_id' => $person->id,
            'employee_number' => 'EMP-PASS',
            'status' => 'active',
            'employment_type' => 'main',
            'is_teacher' => false,
        ]);
        $target = User::factory()->create(['is_active' => true]);
        $target->forceFill(['person_id' => $person->id])->save();

        $this->withApiAuth($this->userWith(['users.manage']));

        $response = $this->postJson('/api/admin/users/reset-password', [
            'profile_type' => 'employee',
            'profile_id' => $employee->id,
        ])->assertOk();

        $issued = (string) $response->json('data.password');

        $this->assertSame(8, strlen($issued), 'Выданный пароль обязан быть восьмизначным, а не пятизначным.');
        $this->assertNotNull($target->refresh()->password_expires_at, 'У выданного пароля обязан быть срок.');
        $this->assertTrue($target->password_expires_at->isFuture());
    }

    public function test_login_refuses_an_expired_password_and_says_what_to_do(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'password' => Hash::make('vremenno8'),
        ]);
        $user->forceFill([
            'must_change_password' => true,
            'password_expires_at' => now()->subDay(),
        ])->save();

        $this->postJson('/api/auth/login', ['login' => $user->email, 'password' => 'vremenno8'])
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'Срок выданного пароля истёк'));
    }

    public function test_a_password_without_a_term_still_works(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'password' => Hash::make('svoy-parol-9'),
        ]);
        $user->forceFill(['password_expires_at' => null])->save();

        $this->postJson('/api/auth/login', ['login' => $user->email, 'password' => 'svoy-parol-9'])
            ->assertOk();
    }

    public function test_setting_your_own_password_drops_the_term(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'password' => Hash::make('vremenno8'),
        ]);
        $user->forceFill([
            'must_change_password' => true,
            'password_expires_at' => now()->addDays(30),
        ])->save();

        $this->withApiAuth($user);

        $this->postJson('/api/account/password', [
            'current_password' => 'vremenno8',
            'password' => 'MoySobstvennyy-9',
            'password_confirmation' => 'MoySobstvennyy-9',
        ])->assertOk();

        $user->refresh();

        $this->assertNull($user->password_expires_at, 'Свой пароль сроку не подлежит.');
        $this->assertFalse((bool) $user->must_change_password);
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(
            ['code' => 'temp_pass_'.substr(md5(json_encode($permissions)), 0, 12)],
            ['name' => 'Временный пароль '.substr(md5(json_encode($permissions)), 0, 8), 'description' => 'Test role'],
        );

        foreach ($permissions as $code) {
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                ['name' => $code, 'module' => 'Test', 'description' => $code, 'system' => true, 'active' => true],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->roles()->sync([$role->id => ['is_primary' => true]]);
        $user->forceFill(['role_id' => $role->id])->save();

        return $user;
    }
}
