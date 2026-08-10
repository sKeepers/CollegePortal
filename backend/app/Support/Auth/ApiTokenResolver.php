<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Разбор bearer-токена, общий для проверки доступа и для ограничителя частоты.
 *
 * Ограничителю нужно знать, чей это запрос, раньше, чем срабатывает `api.token`.
 * `ThrottleRequests` стоит в таблице приоритетов Laravel, `AuthenticateApiToken` — нет,
 * и сортировщик выносит ограничитель в начало цепочки. Поэтому `$request->user()`
 * в момент подсчёта пуст, и ключом счётчика всегда оказывался адрес.
 *
 * Класс никого не пускает: он отвечает «чей это токен», а решение о доступе принимает
 * middleware. Неопознанный токен — это `null`, а не отказ.
 *
 * **Ответ запоминается по самому токену, а не «на запрос».** Контейнер переживает
 * отдельный запрос — и в тестах, и под Octane, — поэтому резолвер, запомнивший просто
 * «пользователя», отдал бы следующему запросу предыдущего. Это ровно тот дефект,
 * который здесь и чинится, только этажом ниже. Замер на нынешнем коде показывал именно
 * такую картину: ограничитель видел пользователя от прошлого запроса.
 */
final class ApiTokenResolver
{
    private bool $resolved = false;

    private ?string $resolvedToken = null;

    private ?User $user = null;

    public function resolve(Request $request): ?User
    {
        $token = $request->bearerToken();

        if ($this->resolved && $this->resolvedToken === $token) {
            return $this->user;
        }

        $this->resolved = true;
        $this->resolvedToken = $token;
        $this->user = $token === null ? null : $this->lookup($token);

        return $this->user;
    }

    private function lookup(string $token): ?User
    {
        return User::query()
            ->where('is_active', true)
            ->where('api_token_lookup_hash', hash('sha256', $token))
            ->whereNotNull('api_token_expires_at')
            ->where('api_token_expires_at', '>', now())
            ->first();
    }
}
