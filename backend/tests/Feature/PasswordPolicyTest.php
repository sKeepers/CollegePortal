<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Student;
use App\Models\User;
use App\Services\AccountProvisioningService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Решение владельца от 11.08.2026: выдаваемый пароль остаётся пятизначным, но после
 * входа портал **предлагает** завести свой. Требования к своему: не короче шести
 * символов, латиница, есть заглавная.
 *
 * Ключевое слово — «предлагает». Отметка `must_change_password` ничего не запрещает:
 * человек с выданным паролем работает в портале как обычно. Она нужна ровно для того,
 * чтобы предложение можно было показать и чтобы оно исчезло, когда своё заведено.
 */
class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->withCredentials();
    }

    /** @return array<string, array{0: string}> */
    public static function rejectedPasswords(): array
    {
        return [
            'короче шести' => ['Ab1de'],
            'без заглавной' => ['abcdef1'],
            'кириллица' => ['Пароль1'],
            'с пробелом' => ['Ab cdef'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedPasswords')]
    public function test_the_account_section_rejects_a_password_that_breaks_the_policy(string $password): void
    {
        $user = $this->userWithPassword('current-pass');

        $this->withApiAuth($user)
            ->postJson('/api/account/password', [
                'current_password' => 'current-pass',
                'password' => $password,
                'password_confirmation' => $password,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_the_account_section_accepts_the_shortest_allowed_password(): void
    {
        $user = $this->userWithPassword('current-pass');

        $this->withApiAuth($user)
            ->postJson('/api/account/password', [
                'current_password' => 'current-pass',
                'password' => 'Abcde1',
                'password_confirmation' => 'Abcde1',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('Abcde1', $user->fresh()->password));
    }

    /**
     * Пароль, выданный порталом, под требования к своему паролю не подпадает —
     * заглавной буквы в нём нет намеренно, его диктуют вслух. Проверять его тем
     * же правилом значило бы запретить выдачу.
     *
     * Но пятизначным числовым он быть перестал (владелец, 23.08.2026, находка
     * аудита 2.6). Прежнее решение от 11.08 держалось на том, что пароль
     * временный; у записи, которой никто не пользовался, «временный» не
     * кончается никогда, а записи заводятся пачкой на весь контингент.
     */
    public function test_provisioning_issues_a_long_password_and_asks_for_a_password_of_your_own(): void
    {
        $student = $this->student();

        $account = app(AccountProvisioningService::class)->provision($student);

        $this->assertMatchesRegularExpression('/^[a-z2-9]{8}$/', $account->password);
        $this->assertTrue($account->user->must_change_password);
        $this->assertNotNull($account->user->password_expires_at, 'У выданного пароля обязан быть срок.');
    }

    public function test_an_administrator_reset_asks_for_a_password_of_your_own(): void
    {
        $student = $this->student();
        $account = app(AccountProvisioningService::class)->provision($student);
        $account->user->forceFill(['must_change_password' => false])->save();

        $this->withApiAuth($this->createApiUser(roleCode: 'admin'))
            ->postJson('/api/admin/users/reset-password', [
                'profile_type' => 'student',
                'profile_id' => $student->id,
            ])
            ->assertOk();

        $this->assertTrue($account->user->fresh()->must_change_password);
    }

    /** Своё заведено — предложение исчезает. Это единственное место, где отметка снимается. */
    public function test_changing_the_password_yourself_clears_the_prompt(): void
    {
        $user = $this->userWithPassword('current-pass');
        $user->forceFill(['must_change_password' => true])->save();

        $this->withApiAuth($user)
            ->postJson('/api/account/password', [
                'current_password' => 'current-pass',
                'password' => 'Abcde1',
                'password_confirmation' => 'Abcde1',
            ])
            ->assertOk();

        $this->assertFalse($user->fresh()->must_change_password);
    }

    /**
     * Признак нужен фронтенду в двух местах: сразу после входа, чтобы отвести человека
     * в раздел, и при восстановлении сессии, чтобы предложение пережило обновление
     * страницы. Поэтому он есть и в `auth/me`, и в самом разделе.
     */
    public function test_the_prompt_is_visible_to_the_frontend_after_login_and_in_the_account_section(): void
    {
        $user = $this->userWithPassword('current-pass');
        $user->forceFill(['must_change_password' => true])->save();

        $this->withApiAuth($user)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true);

        $this->withApiAuth($user)->getJson('/api/account')
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true);
    }

    /** Правка карточки пользователя без пароля не должна возвращать предложение тому, кто своё уже завёл. */
    public function test_editing_a_user_without_a_password_leaves_the_prompt_alone(): void
    {
        $user = $this->userWithPassword('current-pass');
        $user->forceFill(['must_change_password' => false])->save();

        $this->withApiAuth($this->createApiUser(roleCode: 'admin'))
            ->putJson("/api/admin/users/{$user->id}", [
                'role_id' => $user->role_id,
                'name' => 'Новое имя',
                'email' => $user->email,
            ])
            ->assertOk();

        $this->assertFalse($user->fresh()->must_change_password);
    }

    private function userWithPassword(string $password): User
    {
        $user = $this->createApiUser();

        $user->forceFill([
            'email' => 'person@local',
            'password' => Hash::make($password),
            'is_active' => true,
            'must_change_password' => false,
        ])->save();

        return $user;
    }

    private function student(): Student
    {
        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]);

        return Student::create([
            'group_id' => $group->id,
            'last_name' => 'Горбачева',
            'first_name' => 'Татьяна',
            'middle_name' => 'Владимировна',
            'phone' => '+79990000010',
            'status' => 'active',
        ]);
    }
}
