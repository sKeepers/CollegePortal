<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Базы, на которых прогон запускать нельзя ни при каких обстоятельствах.
     *
     * `college_portal` — это рабочая база стенда DEV и она же боевая на PROD.
     * `RefreshDatabase` начинает с `migrate:fresh`, поэтому один прогон,
     * направленный туда, стирает всё: роли, учётные записи, людей, проходы.
     */
    private const FORBIDDEN_DATABASES = ['college_portal'];

    /**
     * Отказаться от прогона, если он направлен на живую базу.
     *
     * За двое суток стенд снесли дважды, и оба раза одинаково: в `.env`
     * worktree временно ставили базу стенда ради замера на живых данных и
     * забывали вернуть перед прогоном. Между этими двумя действиями проходит
     * десяток команд, и внимательность тут не работает — работает отказ.
     *
     * Проверка стоит в `refreshApplication`, а не в `setUp`: приложение к этому
     * моменту уже поднято, значит настройки подключения известны, но
     * `setUpTraits` ещё не выполнялся — то есть `RefreshDatabase` до базы не
     * добрался. Позже было бы поздно.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if (! is_string($database) || ! in_array($database, self::FORBIDDEN_DATABASES, true)) {
            return;
        }

        throw new RuntimeException(
            "Прогон направлен на базу «{$database}» — это рабочая база стенда, а не тестовая. ".
            'RefreshDatabase пересоздаёт схему и стёр бы её целиком. '.
            'Поправьте DB_DATABASE в backend/.env своего worktree: заведите свою базу вида college_portal_<метка>.'
        );
    }

    protected function withApiAuth(?User $user = null): self
    {
        $token = Str::random(80);

        $user ??= $this->createApiUser($token);
        $user->forceFill([
            'api_token_hash' => Hash::make($token),
            'api_token_lookup_hash' => hash('sha256', $token),
            'api_token_expires_at' => now()->addMinutes((int) config('auth.api_token_ttl_minutes', 720)),
            'is_active' => true,
        ])->save();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    protected function createApiUser(?string $token = null, string $roleCode = 'admin'): User
    {
        $role = Role::query()->firstOrCreate(
            ['code' => $roleCode],
            ['name' => str($roleCode)->replace('_', ' ')->title()->toString(), 'description' => null]
        );

        return User::factory()->create([
            'role_id' => $role->id,
            'is_active' => true,
            'api_token_hash' => $token ? Hash::make($token) : null,
            'api_token_lookup_hash' => $token ? hash('sha256', $token) : null,
            'api_token_expires_at' => $token ? now()->addMinutes((int) config('auth.api_token_ttl_minutes', 720)) : null,
        ]);
    }
}
