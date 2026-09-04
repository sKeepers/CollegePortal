<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Пароль задаётся вот этой учётной записи, названной человеком.
 *
 * Сброс по карточке человека (`AccountNotGuessedTest`) при двух действующих
 * записях выбирать между ними отказывается — и отправляет в раздел
 * «Пользователи». А там до 03.09.2026 пароль можно было только **придумать
 * руками** в правке карточки: без срока, без отметки «выдан» и без карточки
 * доступа с логином. То есть отказ отправлял туда, где нужного действия не
 * было.
 *
 * Проверки требуют **двух действующих** записей у одного человека: на одной
 * любой выбор выглядит правильным, и внесённый дефект был бы недостижим.
 */
class PasswordForThisAccountTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: User} обе действующие, у одного человека */
    private function twoWorkingAccounts(): array
    {
        $person = Person::create(['last_name' => 'Двойников', 'first_name' => 'Пётр', 'status' => 'active']);
        $role = Role::query()->firstOrCreate(['code' => 'teacher'], ['name' => 'Преподаватель', 'description' => null]);

        $first = User::factory()->create([
            'role_id' => $role->id,
            'person_id' => $person->id,
            'is_active' => true,
            'username' => 'первая-запись',
            'password' => Hash::make('пароль-первой'),
        ]);

        $second = User::factory()->create([
            'role_id' => $role->id,
            'person_id' => $person->id,
            'is_active' => true,
            'username' => 'вторая-запись',
            'password' => Hash::make('пароль-второй'),
        ]);

        return [$first, $second];
    }

    private function reset(User $user): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/admin/users/'.$user->id.'/reset-password');
    }

    public function test_the_password_goes_to_the_named_account_and_not_to_the_other_one(): void
    {
        [$first, $second] = $this->twoWorkingAccounts();
        $this->withApiAuth();

        $response = $this->reset($second)->assertOk();

        $this->assertSame($second->name, $response->json('data.name'));
        $this->assertSame('вторая-запись', $response->json('data.login'));
        $this->assertTrue(
            Hash::check($response->json('data.password'), $second->fresh()->password),
            'Показанный пароль не подходит к записи, которую назвали: карточка доступа врёт.',
        );
        $this->assertTrue(
            Hash::check('пароль-первой', $first->fresh()->password),
            'Пароль сброшен не той записи — человек остался без входа, а сбросивший уверен, что выдал его.',
        );
    }

    /**
     * Выданный пароль — временный, и это его отличие от набранного руками.
     *
     * Правка карточки в разделе «Пользователи» отметку «сменить» ставит, а срока
     * не даёт вовсе: у записи, которой никто не пользовался, «до первого входа»
     * не наступает никогда, и временный пароль становится постоянным. Замер
     * 23.08.2026 — пять цифр, прожившие годами.
     */
    public function test_the_issued_password_carries_a_term_and_a_demand_to_change_it(): void
    {
        [, $second] = $this->twoWorkingAccounts();
        $this->withApiAuth();

        $this->reset($second)->assertOk();

        $fresh = $second->fresh();
        $this->assertTrue((bool) $fresh->must_change_password, 'Выданный пароль не требует смены.');
        $this->assertNotNull($fresh->password_expires_at, 'У выданного пароля нет срока — он останется у записи навсегда.');
        $this->assertTrue(
            $fresh->password_expires_at->between(now()->addDays(29), now()->addDays(31)),
            'Срок выданного пароля не тот, что у сброса по карточке человека.',
        );
    }

    /**
     * В карточке доступа стоит то, чем человек войдёт.
     *
     * Портал пускает и по `username`, и по `email`, а `username` есть не у
     * каждой записи: заведённая вручную получает только почту. Пустая строка
     * «Логин» на распечатанной карточке — это пароль без входа.
     */
    public function test_the_login_in_the_card_is_the_one_the_portal_lets_in_by(): void
    {
        [, $second] = $this->twoWorkingAccounts();
        $second->forceFill(['username' => null])->save();
        $this->withApiAuth();

        $response = $this->reset($second)->assertOk();

        $this->assertSame($second->email, $response->json('data.login'));

        // Доходим до предмета: карточкой доступа входят, а не любуются.
        $this->postJson('/api/auth/login', [
            'login' => $response->json('data.login'),
            'password' => $response->json('data.password'),
        ])->assertOk();
    }

    public function test_a_disabled_account_is_refused_with_the_reason(): void
    {
        [, $second] = $this->twoWorkingAccounts();
        $second->forceFill(['is_active' => false])->save();
        $this->withApiAuth();

        $this->reset($second)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Учетная запись отключена — по выданному паролю в нее не войти. Сначала включите запись, потом сбрасывайте пароль.');

        $this->assertTrue(
            Hash::check('пароль-второй', $second->fresh()->password),
            'Отключённой записи выдали пароль: войти по нему нельзя, а выдавший будет уверен в обратном.',
        );
    }

    /**
     * Свой пароль этой ручкой не сбрасывается.
     *
     * Выданный показывается один раз, а смена пароля спрашивает текущий: не
     * записав показанное, администратор закрывает вход себе самому — и вернуть
     * его будет некому.
     */
    public function test_your_own_password_is_not_reset_here(): void
    {
        $me = $this->createApiUser(null, 'admin');
        $me->forceFill(['password' => Hash::make('мой-пароль')])->save();
        $this->withApiAuth($me);

        $this->reset($me)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Свой пароль меняйте в разделе «Моя учётная запись»: выданный показывается один раз, и, не записав его, вы закроете вход себе.');

        $this->assertTrue(Hash::check('мой-пароль', $me->fresh()->password), 'Портал сбросил пароль тому, кто его сбрасывал.');
    }

    /**
     * След в журнале называет, что запись выбрал человек.
     *
     * Сброс по карточке и выбор руками иначе неразличимы, а разбираются потом
     * именно случаи «кому и почему выдали пароль».
     */
    public function test_the_trace_names_that_the_account_was_chosen_by_hand(): void
    {
        [, $second] = $this->twoWorkingAccounts();
        $this->withApiAuth();

        $this->reset($second)->assertOk();

        $log = AuditLog::query()->where('module', 'users')->where('action', 'reset_password')->latest('id')->first();

        $this->assertNotNull($log, 'Выдача пароля не оставила следа в журнале.');
        $this->assertSame($second->id, (int) $log->entity_id);
        $this->assertNotNull($log->user_id, 'В следе не назван тот, кто выдал пароль.');
        $this->assertTrue((bool) ($log->new_values['chosen_by_hand'] ?? false), 'След не отличает выбор руками от сброса по карточке.');

        // Пароля в журнале быть не должно: он показывается один раз и нигде не
        // хранится — ни в базе, ни в следе.
        $this->assertStringNotContainsString('password"', json_encode($log->new_values, JSON_UNESCAPED_UNICODE));
    }

    public function test_the_handle_is_closed_to_those_without_the_right(): void
    {
        [, $second] = $this->twoWorkingAccounts();
        $stranger = $this->createApiUser(null, 'security');
        $this->withApiAuth($stranger);

        $this->reset($second)->assertForbidden();

        $this->assertTrue(
            Hash::check('пароль-второй', $second->fresh()->password),
            'Пароль выдан тем, у кого нет права на учётные записи.',
        );
    }
}
