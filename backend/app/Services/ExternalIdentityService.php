<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserIdentity;
use App\Support\Auth\Providers\ExternalIdentityProviders;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Привязка и отвязка внешних способов входа.
 *
 * Главное правило всей затеи: **вход через мессенджер только привязывается к уже
 * существующей учётной записи и никогда не создаёт новую.** Поэтому здесь нет и не
 * должно появиться метода «войти и завести пользователя»: привязку делает вошедший
 * человек из своего кабинета, подтверждая её паролем. Иначе любой владелец аккаунта
 * в мессенджере получил бы доступ к порталу.
 */
class ExternalIdentityService
{
    public function __construct(private readonly ExternalIdentityProviders $providers)
    {
    }

    /**
     * @param array<string, mixed> $payload ответ провайдера
     */
    public function link(User $user, string $providerCode, array $payload, Request $request): UserIdentity
    {
        $provider = $this->providers->get($providerCode);

        if ($provider === null) {
            throw ValidationException::withMessages(['provider' => 'Такой способ входа не подключён.']);
        }

        $identity = $provider->verify($payload);

        if ($identity === null) {
            // Не разделяем «подпись не сошлась» и «ответ просрочен»: разница полезна
            // только тому, кто подбирает.
            throw ValidationException::withMessages(['provider' => 'Ответ провайдера не прошёл проверку.']);
        }

        $existing = UserIdentity::query()
            ->where('provider', $providerCode)
            ->where('provider_user_id', $identity->providerUserId)
            ->first();

        if ($existing !== null) {
            AuditLogService::log('auth', 'external_identity_link_refused', $existing, null, [
                'provider' => $providerCode,
                'reason' => 'already_linked',
            ], $request, $user);

            // Чей это аккаунт — не сообщаем: иначе привязка превращается в способ
            // выяснять, кто из сотрудников есть в мессенджере.
            throw ValidationException::withMessages([
                'provider' => 'Этот аккаунт уже привязан к учётной записи портала.',
            ]);
        }

        $created = UserIdentity::create([
            'user_id' => $user->id,
            'provider' => $providerCode,
            'provider_user_id' => $identity->providerUserId,
            'display_name' => $identity->displayName,
            'linked_at' => now(),
            'linked_by' => $user->id,
        ]);

        AuditLogService::log('auth', 'external_identity_linked', $created, null, [
            'provider' => $providerCode,
            'display_name' => $identity->displayName,
        ], $request, $user);

        return $created;
    }

    /**
     * Кому принадлежит проверенный ответ провайдера — или `null`, если подпись не сошлась
     * либо аккаунт ни к кому не привязан.
     *
     * Это **не** «войти и завести пользователя»: метод только ищет существующую привязку
     * и ничего не создаёт. Именно поэтому вход через мессенджер не может стать способом
     * регистрации даже побочным эффектом. Пускать или нет решает контроллер — здесь
     * проверяется лишь то, чей это аккаунт.
     *
     * @param array<string, mixed> $payload ответ провайдера
     */
    public function resolveLinkedUser(string $providerCode, array $payload): ?User
    {
        $provider = $this->providers->get($providerCode);
        $identity = $provider?->verify($payload);

        if ($identity === null) {
            return null;
        }

        // Ищем по коду **привязки**, а не по коду способа входа: у мини-приложения
        // они разные, и вошедший из Telegram-приложения иначе оказался бы
        // непривязанным при живой привязке Telegram.
        return UserIdentity::query()
            ->where('provider', $provider->identityCode())
            ->where('provider_user_id', $identity->providerUserId)
            ->first()?->user;
    }

    /**
     * Отвязка. Доступна и самому человеку, и администратору: если человек потерял
     * доступ к мессенджеру, снять привязку должен кто-то ещё, иначе аккаунт занят
     * навсегда и никто другой его не привяжет.
     */
    public function unlink(UserIdentity $identity, User $actor, Request $request): void
    {
        $owner = $identity->user;

        // Защита от того, чтобы отвязка не оставила учётную запись без единого способа
        // войти. Сегодня пароль есть у всех, и условие не срабатывает, но оно должно
        // существовать раньше, чем появится вход без пароля, а не после.
        if (blank($owner?->password) && $owner?->identities()->count() === 1) {
            throw ValidationException::withMessages([
                'identity' => 'Это единственный способ входа в учётную запись. Сначала задайте пароль.',
            ]);
        }

        AuditLogService::log('auth', 'external_identity_unlinked', $identity, [
            'provider' => $identity->provider,
            'user_id' => $identity->user_id,
        ], ['by_administrator' => $actor->id !== $identity->user_id], $request, $actor);

        $identity->delete();
    }
}
