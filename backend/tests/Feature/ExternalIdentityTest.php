<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserIdentity;
use App\Support\Auth\Providers\ExternalIdentity;
use App\Support\Auth\Providers\ExternalIdentityProvider;
use App\Support\Auth\Providers\ExternalIdentityProviders;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `AUTH-005`: общий слой внешних способов входа.
 *
 * Настоящих провайдеров ещё нет — Telegram придёт с `AUTH-003`, MAX с `AUTH-004`.
 * Здесь подставлен поддельный: он позволяет проверить правила слоя, не выдумывая
 * за провайдера его подпись. Проверяются именно правила, ради которых слой и делался.
 */
class ExternalIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'мой-текущий-пароль';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_a_linked_account_cannot_be_taken_by_a_second_user(): void
    {
        $this->useProvider();
        $first = $this->userWithPassword();
        $second = $this->userWithPassword();

        $this->withApiAuth($first);
        $this->linkRequest('внешний-1')->assertCreated();

        // Тот же внешний аккаунт, другой человек: иначе вход через мессенджер
        // становится способом попасть в чужую учётную запись.
        $this->withApiAuth($second);
        $this->linkRequest('внешний-1')
            ->assertStatus(422)
            ->assertJsonPath('errors.provider.0', 'Этот аккаунт уже привязан к учётной записи портала.');

        $this->assertSame(1, UserIdentity::query()->where('provider_user_id', 'внешний-1')->count());
    }

    public function test_the_refusal_does_not_say_whose_account_it_is(): void
    {
        $this->useProvider();
        $owner = $this->userWithPassword();
        $owner->forceFill(['name' => 'Владелец Аккаунта', 'email' => 'owner@local'])->save();
        $stranger = $this->userWithPassword();

        $this->withApiAuth($owner);
        $this->linkRequest('внешний-2')->assertCreated();

        $this->withApiAuth($stranger);
        $body = $this->linkRequest('внешний-2')->assertStatus(422)->getContent();

        $this->assertStringNotContainsString('Владелец', $body);
        $this->assertStringNotContainsString('owner@local', $body);
    }

    public function test_linking_and_unlinking_need_the_current_password(): void
    {
        $this->useProvider();
        $user = $this->userWithPassword();
        $this->withApiAuth($user);

        $this->postJson('/api/account/identities', [
            'provider' => 'проверочный',
            'current_password' => 'не-тот-пароль',
            'payload' => ['id' => 'внешний-3'],
        ])->assertStatus(422)->assertJsonPath('errors.current_password.0', 'Текущий пароль указан неверно.');

        $this->assertSame(0, UserIdentity::query()->count());

        $identity = $this->linkRequest('внешний-3')->assertCreated();
        $id = $identity->json('data.id');

        $this->deleteJson("/api/account/identities/{$id}", ['current_password' => 'не-тот-пароль'])->assertStatus(422);
        $this->assertSame(1, UserIdentity::query()->count());

        $this->deleteJson("/api/account/identities/{$id}", ['current_password' => self::PASSWORD])->assertOk();
        $this->assertSame(0, UserIdentity::query()->count());
    }

    public function test_a_forged_answer_from_the_provider_is_refused(): void
    {
        $this->useProvider(verifies: false);
        $this->withApiAuth($this->userWithPassword());

        $this->linkRequest('внешний-4')->assertStatus(422)->assertJsonPath('errors.provider.0', 'Ответ провайдера не прошёл проверку.');
        $this->assertSame(0, UserIdentity::query()->count());
    }

    public function test_an_unknown_provider_is_refused(): void
    {
        $this->withApiAuth($this->userWithPassword());

        $this->postJson('/api/account/identities', [
            'provider' => 'несуществующий',
            'current_password' => self::PASSWORD,
            'payload' => ['id' => 'внешний-5'],
        ])->assertStatus(422)->assertJsonPath('errors.provider.0', 'Такой способ входа не подключён.');
    }

    public function test_a_person_cannot_unlink_someone_elses_method(): void
    {
        $this->useProvider();
        $owner = $this->userWithPassword();
        $this->withApiAuth($owner);
        $id = $this->linkRequest('внешний-6')->json('data.id');

        $this->withApiAuth($this->userWithPassword());
        $this->deleteJson("/api/account/identities/{$id}", ['current_password' => self::PASSWORD])->assertNotFound();

        $this->assertSame(1, UserIdentity::query()->count());
    }

    /**
     * Ради этого администратору и дано право: человек потерял доступ к мессенджеру,
     * и без отвязки со стороны внешний аккаунт остаётся занятым навсегда.
     */
    public function test_an_administrator_can_unlink_for_someone_who_lost_access(): void
    {
        $this->useProvider();
        $owner = $this->userWithPassword();
        $this->withApiAuth($owner);
        $id = $this->linkRequest('внешний-7')->json('data.id');

        $this->withApiAuth($this->createApiUser(Str::random(80), 'admin'));

        $this->getJson("/api/admin/users/{$owner->id}/identities")->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/admin/users/{$owner->id}/identities/{$id}")->assertOk();

        $this->assertSame(0, UserIdentity::query()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'external_identity_unlinked']);
    }

    public function test_the_list_says_when_there_is_nothing_to_link_yet(): void
    {
        $this->withApiAuth($this->userWithPassword());

        // Пустой список провайдеров — не ошибка, а состояние: слой готов, Telegram
        // и MAX ещё не сделаны. Интерфейс по этому признаку прячет кнопку привязки.
        $this->getJson('/api/account/identities')->assertOk()->assertJsonPath('available', []);
    }

    private function linkRequest(string $externalId)
    {
        return $this->postJson('/api/account/identities', [
            'provider' => 'проверочный',
            'current_password' => self::PASSWORD,
            'payload' => ['id' => $externalId],
        ]);
    }

    private function useProvider(bool $verifies = true): void
    {
        $provider = new class($verifies) implements ExternalIdentityProvider
        {
            public function __construct(private readonly bool $verifies)
            {
            }

            public function code(): string
            {
                return 'проверочный';
            }

            /** Привязка и способ входа тут совпадают — расходятся они только у мини-приложения. */
            public function identityCode(): string
            {
                return 'проверочный';
            }

            public function name(): string
            {
                return 'Проверочный способ';
            }

            /** У поддельного провайдера кнопки нет, рисовать браузеру нечего. */
            public function publicConfig(): array
            {
                return [];
            }

            public function verify(array $payload): ?ExternalIdentity
            {
                return $this->verifies ? new ExternalIdentity((string) $payload['id'], 'аккаунт '.$payload['id']) : null;
            }
        };

        $this->app->singleton(ExternalIdentityProviders::class, fn (): ExternalIdentityProviders => new ExternalIdentityProviders([$provider]));
    }

    private function userWithPassword(): User
    {
        $user = $this->createApiUser(Str::random(80));
        $user->forceFill(['password' => Hash::make(self::PASSWORD)])->save();

        return $user;
    }
}
