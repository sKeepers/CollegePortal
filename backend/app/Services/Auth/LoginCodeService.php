<?php

namespace App\Services\Auth;

use App\Models\LoginCode;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\AuditLogService;
use App\Support\LoginIdentifier;
use App\Support\Notifications\NotificationChannels;
use Illuminate\Support\Facades\Hash;

/**
 * Вход по коду из бота.
 *
 * Человек вводит логин, портал присылает шестизначный код в мессенджер, человек
 * вводит код — и входит. Пароль не участвует: подтверждением личности служит то,
 * что человек владеет привязанным аккаунтом мессенджера, а привязывал он его,
 * уже войдя паролем.
 *
 * Два правила, из которых всё остальное следует.
 *
 * **Учётная запись не заводится никогда** — как и во внешнем входе. Нет привязки
 * — нет входа.
 *
 * **Наружу не видно, существует ли логин.** Ответ на запрос кода одинаков всегда:
 * иначе форма входа превращается в способ перебирать учётные записи колледжа, а
 * заодно узнавать, у кого привязан мессенджер.
 *
 * Код уходит **мимо подписок на уведомления**: на код входа не подписываются, и
 * снятая галочка «расписание на завтра» не должна отнимать способ войти.
 */
class LoginCodeService
{
    /** Столько живёт код. Дольше — окно для подбора, короче — не успеть переключиться в мессенджер. */
    public const TTL_SECONDS = 300;

    /** Столько раз можно ошибиться, прежде чем код сгорит. */
    public const MAX_ATTEMPTS = 5;

    public function __construct(private readonly NotificationChannels $channels)
    {
    }

    /**
     * Запросить код.
     *
     * Возвращает, куда он ушёл, — или `null`, если отправлять было некому. Наружу
     * эта разница не выходит: её знает только журнал.
     *
     * @return array{channel: string, name: string}|null
     */
    public function request(string $login, ?string $ip = null): ?array
    {
        $user = $this->findUser($login);

        if ($user === null || ! $user->is_active) {
            return null;
        }

        $target = $this->deliveryTarget($user);

        if ($target === null) {
            AuditLogService::log('auth', 'login_code_no_channel', $user, null, ['login' => $login]);

            return null;
        }

        [$channel, $chatId] = $target;

        // Прежние коды этого человека гасятся: два живых кода означают, что
        // подобрать надо любой из двух, а человек всё равно смотрит на последний.
        LoginCode::query()
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = $this->generateCode();

        LoginCode::query()->create([
            'user_id' => $user->id,
            'channel' => $channel->code(),
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
            'request_ip' => $ip,
        ]);

        $minutes = (int) round(self::TTL_SECONDS / 60);
        $sent = $channel->send($chatId, "Код для входа в портал: {$code}\nДействует {$minutes} мин. Если вы не входили — просто не вводите его.");

        AuditLogService::log('auth', $sent ? 'login_code_sent' : 'login_code_send_failed', $user, null, [
            'channel' => $channel->code(),
        ]);

        return $sent ? ['channel' => $channel->code(), 'name' => $channel->name()] : null;
    }

    /**
     * Проверить код и вернуть человека, которому он принадлежит.
     *
     * Неверная попытка считается у самого кода: после исчерпания он гасится
     * целиком, и подбирать становится нечего.
     */
    public function verify(string $login, string $code): ?User
    {
        $user = $this->findUser($login);

        if ($user === null || ! $user->is_active) {
            return null;
        }

        $record = LoginCode::query()
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if ($record === null) {
            return null;
        }

        if (! Hash::check($code, $record->code_hash)) {
            $record->increment('attempts');

            if ($record->attempts >= self::MAX_ATTEMPTS) {
                $record->forceFill(['consumed_at' => now()])->save();
                AuditLogService::log('auth', 'login_code_burned', $user, null, ['attempts' => $record->attempts]);
            }

            return null;
        }

        $record->forceFill(['consumed_at' => now()])->save();

        return $user;
    }

    /**
     * Куда писать: первый привязанный мессенджер, у которого начат диалог **и**
     * есть работающий канал доставки. Бот не может написать первым, поэтому без
     * `chat_id` привязка для входа бесполезна.
     *
     * @return array{0: \App\Support\Notifications\NotificationChannel, 1: string}|null
     */
    private function deliveryTarget(User $user): ?array
    {
        $identities = UserIdentity::query()
            ->where('user_id', $user->id)
            ->whereNotNull('chat_id')
            ->orderBy('id')
            ->get();

        foreach ($identities as $identity) {
            $channel = $this->channels->get((string) $identity->provider);

            if ($channel !== null) {
                return [$channel, (string) $identity->chat_id];
            }
        }

        return null;
    }

    /**
     * Шесть цифр, включая ведущие нули: код читают с экрана телефона и набирают
     * руками, поэтому он должен быть коротким и без букв.
     */
    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Поиск человека тот же, что при входе паролем: адрес, логин, телефон в любом
     * написании. Разойтись эти два способа не имеют права — иначе логин, которым
     * человек входит паролем, для кода окажется «не найден».
     */
    private function findUser(string $login): ?User
    {
        $phoneLogins = LoginIdentifier::variants($login);

        return User::query()
            ->with(['role.permissions', 'roles.permissions'])
            ->where(function ($query) use ($login, $phoneLogins): void {
                $query->where('email', $login)
                    ->orWhere('username', $login)
                    ->orWhereIn('username', $phoneLogins)
                    ->orWhereHas('person', fn ($person) => $person->whereIn('phone', $phoneLogins))
                    ->orWhereHas('student', fn ($student) => $student->whereIn('phone', $phoneLogins))
                    ->orWhereHas('teacher', fn ($teacher) => $teacher->whereIn('phone', $phoneLogins));
            })
            ->first();
    }

    /** Подсказка для формы: сколько живёт код. */
    public function ttlSeconds(): int
    {
        return self::TTL_SECONDS;
    }
}
