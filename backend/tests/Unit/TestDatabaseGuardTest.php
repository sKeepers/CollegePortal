<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabaseGuard;

/**
 * Сторож, отказывающий прогону на живой базе.
 *
 * Проверяется он здесь, а не живым прогоном: чтобы убедиться, что сторож ловит
 * рабочую базу, пришлось бы направить на неё прогон — то есть сделать ровно то,
 * от чего он защищает. Правило вынесено в отдельный класс именно ради этого.
 *
 * Наследуется от `PHPUnit\Framework\TestCase`, а не от общего `Tests\TestCase`:
 * приложение здесь не нужно, а общий как раз и вызывает проверяемый сторож.
 */
class TestDatabaseGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('CP_WORKTREE');

        parent::tearDown();
    }

    /**
     * Второе правило: имя базы обязано называть worktree.
     *
     * Это не про боевую базу, а про соседей: четыре области гоняют прогоны
     * одновременно, и одно имя на двоих означает, что `RefreshDatabase`
     * пересоздаёт схему под ногами у чужого прогона. Оба видят падения, не
     * связанные с их правками, и ищут их у себя в коде.
     */
    public function test_it_refuses_a_test_database_belonging_to_a_neighbour(): void
    {
        putenv('CP_WORKTREE=dbguard');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/не называет этот worktree/su');

        TestDatabaseGuard::assertSafe('pgsql', 'college_portal_test_gate');
    }

    public function test_it_lets_through_a_database_named_after_the_worktree(): void
    {
        putenv('CP_WORKTREE=dbguard');

        TestDatabaseGuard::assertSafe('pgsql', 'college_portal_test_dbguard');

        $this->addToAssertionCount(1);
    }

    /**
     * Общая база — законное исключение: на ней идёт обычный прогон, и имени
     * worktree она не содержит по замыслу. Сторож, мешающий в самом частом
     * случае, был бы отключён, а отключённый сторож хуже отсутствующего.
     */
    public function test_it_lets_the_shared_test_database_through_from_any_worktree(): void
    {
        putenv('CP_WORKTREE=dbguard');

        TestDatabaseGuard::assertSafe('pgsql', 'college_portal_test');

        $this->addToAssertionCount(1);
    }

    /**
     * Без переменной остаётся первое правило. Имя worktree внутри контейнера
     * иначе не узнать: монтируется только `backend`, каталог виден как
     * `/var/www/html`. Требовать того, что нельзя проверить, сторож не должен.
     */
    public function test_without_the_worktree_name_it_falls_back_to_the_first_rule(): void
    {
        putenv('CP_WORKTREE');

        TestDatabaseGuard::assertSafe('pgsql', 'college_portal_test_gate');

        $this->addToAssertionCount(1);
    }

    public function test_it_refuses_the_working_database(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/college_portal.*не объявлена тестовой/su');

        TestDatabaseGuard::assertSafe('pgsql', 'college_portal');
    }

    /**
     * Белый список ловит и то, чего чёрный не видел: базу соседа, боевую базу
     * под другим именем и опечатку в слове `test`.
     *
     * @dataProvider livingDatabases
     */
    public function test_it_refuses_anything_not_named_as_a_test(string $database): void
    {
        $this->expectException(RuntimeException::class);

        TestDatabaseGuard::assertSafe('pgsql', $database);
    }

    /** @return iterable<string, array{string}> */
    public static function livingDatabases(): iterable
    {
        yield 'база соседей' => ['college_portal_storm'];
        yield 'боевая под другим именем' => ['college_prod'];
        yield 'опечатка в слове test' => ['college_portal_tets'];
        yield 'приём с несуществующим именем' => ['college_portal_NO_SUCH_DB_curator'];
    }

    /**
     * Заглушку `NO_SUCH_DB` области ставят намеренно, пока имя базы не выбрано.
     * Человек, наткнувшийся на неё, ничего не ломал — и текст обязан говорить
     * ему это, а не «имя не содержит test»: иначе он пойдёт искать поломку.
     */
    public function test_a_placeholder_says_the_run_is_not_set_up_yet(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/заглушка.*прогон здесь ещё не настроен/su');

        TestDatabaseGuard::assertSafe('pgsql', 'college_portal_NO_SUCH_DB_curator');
    }

    public function test_it_refuses_an_empty_name(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/не задано/su');

        // Пустое значение в `.env` — это пустая строка, а не «как по умолчанию».
        TestDatabaseGuard::assertSafe('pgsql', '');
    }

    /** @dataProvider safeDatabases */
    public function test_it_lets_a_test_database_through(string $database): void
    {
        TestDatabaseGuard::assertSafe('pgsql', $database);

        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{string}> */
    public static function safeDatabases(): iterable
    {
        // `college_portal_test` — та же база, что заводит CI.
        yield 'общая тестовая' => ['college_portal_test'];
        yield 'своя у области' => ['college_portal_test_guard'];
        yield 'слово test в середине' => ['portal_test_gate'];
    }

    /**
     * Прогон по умолчанию идёт в памяти и до сервера баз не доходит: запрещать
     * там нечего, а отказ сломал бы обычный `php artisan test`.
     */
    public function test_it_leaves_sqlite_alone(): void
    {
        TestDatabaseGuard::assertSafe('sqlite', ':memory:');
        TestDatabaseGuard::assertSafe('sqlite', '/var/www/html/database/database.sqlite');

        $this->addToAssertionCount(1);
    }
}
