<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Сброс пароля по карточке не угадывает, какой записи его сбрасывать.
 *
 * До 01.09.2026 в `AdminUserController::profileUser()` стоял `first()` без
 * порядка и без отбора действующих. При двух учётных записях у одного человека
 * база отдавала любую, и сброс **дважды попал в отключённую**: человек остался
 * без входа, а тот, кто сбрасывал, был уверен, что выдал пароль.
 *
 * Проверки здесь требуют **двух** записей у одного человека — на одной
 * `first()` ведёт себя правильно, и внесённый дефект был бы недостижим.
 */
class AccountNotGuessedTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Teacher, 1: User, 2: User} */
    private function personWithTwoAccounts(): array
    {
        $person = Person::create(['last_name' => 'Двойников', 'first_name' => 'Иван', 'status' => 'active']);
        $teacher = Teacher::create([
            'last_name' => 'Двойников',
            'first_name' => 'Иван',
            'person_id' => $person->id,
            'is_active' => true,
        ]);

        $role = Role::query()->firstOrCreate(['code' => 'teacher'], ['name' => 'Преподаватель', 'description' => null]);

        // Отключённая заводится **первой**: именно её и отдавал `first()` без
        // порядка, и она обязана остаться первой по идентификатору, иначе
        // внесённый дефект перестанет быть достижимым.
        $disabled = User::factory()->create([
            'role_id' => $role->id,
            'person_id' => $person->id,
            'is_active' => false,
            'password' => Hash::make('старый-отключённый'),
        ]);

        $active = User::factory()->create([
            'role_id' => $role->id,
            'person_id' => $person->id,
            'is_active' => true,
            'password' => Hash::make('старый-действующий'),
        ]);

        return [$teacher, $disabled, $active];
    }

    private function reset(Teacher $teacher): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/admin/users/reset-password', [
            'profile_type' => 'teacher',
            'profile_id' => $teacher->id,
        ]);
    }

    public function test_the_reset_reaches_the_working_account_and_not_the_disabled_one(): void
    {
        [$teacher, $disabled, $active] = $this->personWithTwoAccounts();
        $this->withApiAuth();

        $response = $this->reset($teacher)->assertOk();

        // Сверяемся по паролю, а не по логину: `username` фабрика не заполняет,
        // и сравнение двух `null` прошло бы вхолостую — проверка молчала бы при
        // любом поведении портала.
        $this->assertSame($active->name, $response->json('data.name'));
        $this->assertFalse(
            Hash::check('старый-действующий', $active->fresh()->password),
            'Действующей записи пароль не сброшен — значит сброс ушёл не туда.',
        );
        $this->assertTrue(
            Hash::check('старый-отключённый', $disabled->fresh()->password),
            'Сброшен пароль отключённой записи: человек останется без входа, а сбросивший будет думать, что выдал пароль.',
        );
    }

    public function test_two_working_accounts_are_not_chosen_between(): void
    {
        [$teacher, $disabled] = $this->personWithTwoAccounts();
        $disabled->forceFill(['is_active' => true])->save();
        $this->withApiAuth();

        $this->reset($teacher)
            ->assertStatus(422)
            ->assertJsonPath('message', 'У этого человека несколько действующих учетных записей (2). Портал не выбирает за вас: откройте раздел «Пользователи» и сбросьте пароль нужной.');
    }

    /**
     * Отключённая запись — не «нет записи».
     *
     * Сказать «создайте учётную запись» тому, у кого она есть, значит отправить
     * его заводить вторую. А две записи у одного человека и есть причина всей
     * этой правки.
     */
    public function test_a_disabled_account_is_not_reported_as_no_account(): void
    {
        [$teacher, , $active] = $this->personWithTwoAccounts();
        $active->forceFill(['is_active' => false])->save();
        $this->withApiAuth();

        $this->reset($teacher)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Учетная запись этого человека отключена. Включите ее в разделе «Пользователи», потом сбрасывайте пароль.');
    }

    /**
     * Карточка, прямо называющая запись, снимает вопрос о выборе.
     *
     * `user_id` в карточке — не угадывание, а указание. Когда оно есть, двух
     * кандидатов нет вовсе, и отказ «несколько действующих» был бы здесь
     * помехой, а не защитой.
     */
    public function test_an_account_named_by_the_card_leaves_nothing_to_choose(): void
    {
        [$teacher, $disabled, $active] = $this->personWithTwoAccounts();
        $disabled->forceFill(['is_active' => true])->save();
        $teacher->forceFill(['user_id' => $active->id])->save();
        $this->withApiAuth();

        $response = $this->reset($teacher)->assertOk();

        $this->assertSame($active->name, $response->json('data.name'));
        $this->assertTrue(
            Hash::check('старый-отключённый', $disabled->fresh()->password),
            'Портал сбросил пароль не той записи, которую назвала карточка.',
        );
    }
}
