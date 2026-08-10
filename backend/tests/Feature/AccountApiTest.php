<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Раздел «Моя учётная запись». До него личная страница в портале была ровно одна и
 * только для студентов, а пароль менял администратор.
 *
 * Прав у раздела нет намеренно: своей почтой, телефоном и паролем распоряжается
 * любой вошедший. Проверки здесь про то, что он не может распорядиться чужими.
 */
class AccountApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_any_authenticated_role_can_open_its_own_account(): void
    {
        $this->withApiAuth($this->createApiUser(Str::random(80), 'teacher'));

        $this->getJson('/api/account')->assertOk()->assertJsonStructure(['data' => ['name', 'login', 'role', 'has_person']]);
    }

    public function test_contacts_are_written_to_the_person_and_mirrored_to_the_profile(): void
    {
        [$user, $person] = $this->userWithPerson();
        $teacher = Teacher::create(['person_id' => $person->id, 'last_name' => 'Власова', 'first_name' => 'Елена', 'is_active' => true]);
        $this->withApiAuth($user);

        $this->patchJson('/api/account/contacts', ['email' => 'vlasova@skki.test', 'phone' => '79990001122'])
            ->assertOk()
            ->assertJsonPath('data.email', 'vlasova@skki.test');

        // Общие данные принадлежат человеку, а копия в карточке приходит зеркалом.
        $this->assertDatabaseHas('people', ['id' => $person->id, 'email' => 'vlasova@skki.test', 'phone' => '79990001122']);
        $this->assertDatabaseHas('teachers', ['id' => $teacher->id, 'email' => 'vlasova@skki.test', 'phone' => '79990001122']);
    }

    public function test_an_account_without_a_person_says_so_instead_of_failing(): void
    {
        $this->withApiAuth($this->createApiUser(Str::random(80)));

        $this->patchJson('/api/account/contacts', ['email' => 'nowhere@skki.test'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'account_without_person');
    }

    public function test_the_password_changes_only_with_the_current_one(): void
    {
        [$user] = $this->userWithPerson('старый-пароль-1');
        $this->withApiAuth($user);

        $this->postJson('/api/account/password', [
            'current_password' => 'не-тот-пароль',
            'password' => 'новый-пароль-2',
            'password_confirmation' => 'новый-пароль-2',
        ])->assertStatus(422)->assertJsonPath('errors.current_password.0', 'Текущий пароль указан неверно.');

        $this->assertTrue(Hash::check('старый-пароль-1', $user->fresh()->password));

        $this->postJson('/api/account/password', [
            'current_password' => 'старый-пароль-1',
            'password' => 'новый-пароль-2',
            'password_confirmation' => 'новый-пароль-2',
        ])->assertOk();

        $this->assertTrue(Hash::check('новый-пароль-2', $user->fresh()->password));
    }

    public function test_a_short_or_unconfirmed_password_is_refused(): void
    {
        [$user] = $this->userWithPerson('старый-пароль-1');
        $this->withApiAuth($user);

        $this->postJson('/api/account/password', [
            'current_password' => 'старый-пароль-1',
            'password' => 'корот1',
            'password_confirmation' => 'корот1',
        ])->assertStatus(422);

        $this->postJson('/api/account/password', [
            'current_password' => 'старый-пароль-1',
            'password' => 'достаточно-длинный',
            'password_confirmation' => 'другое-подтверждение',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('старый-пароль-1', $user->fresh()->password));
    }

    public function test_the_account_section_never_touches_someone_else(): void
    {
        [$user, $person] = $this->userWithPerson();
        $stranger = Person::create(['last_name' => 'Посторонний', 'first_name' => 'Пётр', 'email' => 'stranger@skki.test', 'status' => 'active']);
        $this->withApiAuth($user);

        $this->patchJson('/api/account/contacts', ['email' => 'mine@skki.test'])->assertOk();

        $this->assertDatabaseHas('people', ['id' => $stranger->id, 'email' => 'stranger@skki.test']);
        $this->assertDatabaseHas('people', ['id' => $person->id, 'email' => 'mine@skki.test']);
    }

    /** @return array{0: User, 1: Person} */
    private function userWithPerson(string $password = 'начальный-пароль'): array
    {
        $person = Person::create(['last_name' => 'Власова', 'first_name' => 'Елена', 'status' => 'active']);
        $user = $this->createApiUser(Str::random(80));

        $user->forceFill([
            'password' => Hash::make($password),
            'person_id' => $person->id,
            'person_type' => 'person',
        ])->save();

        return [$user, $person];
    }
}
