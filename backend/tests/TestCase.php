<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
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
     *
     * Само правило вынесено в `TestDatabaseGuard`: там его видно целиком и там
     * его можно проверить на дефекте, не пуская прогон по живой базе ради
     * проверки сторожа.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $connection = config('database.default');

        TestDatabaseGuard::assertSafe(
            config("database.connections.{$connection}.driver"),
            config("database.connections.{$connection}.database"),
        );
    }

    /**
     * Пиковая память прогона печатается всегда, а не только при падении.
     *
     * 29.08.2026 два тяжёлых теста мигнули, и спор «память или нет» разрешить
     * было нечем: у зелёных прогонов пика никто не мерил, а сравнивать надо
     * именно с ними. `CLAUDE.md` описывает упор в память как «Allowed memory
     * size exhausted» и десяток несвязанных падений — но чтобы отличить «близко
     * к потолку» от «далеко», нужно число, а не признак.
     *
     * Печатается один раз, в самом конце: строка в выводе прогона стоит дёшево,
     * а следующий такой спор превращает в замер.
     */
    private static bool $peakReported = false;

    private function reportPeakMemoryOnce(): void
    {
        if (self::$peakReported) {
            return;
        }

        self::$peakReported = true;

        register_shutdown_function(static function (): void {
            $limit = (string) ini_get('memory_limit');
            $peak = memory_get_peak_usage(true);

            fwrite(STDOUT, sprintf(
                "\n  Пик памяти за прогон: %d МБ при лимите %s\n",
                (int) round($peak / 1024 / 1024),
                $limit,
            ));
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reportPeakMemoryOnce();
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
