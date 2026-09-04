<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Число рабочих процессов доезжает до релизного образа, а не только до стенда.
 *
 * `de2d8def2` подняла php-fpm с пяти процессов до двадцати четырёх — и положила
 * число в `docker/portal-entrypoint.sh`, который подключён к образу **стенда**.
 * В `Dockerfile.release` ни этого файла, ни `ENTRYPOINT` не было: боевой образ
 * стартовал `php-fpm` напрямую и оставался с умолчанием `php:fpm`. Замер
 * 04.09.2026 изнутри собранного релизного образа: **до правки
 * `pm.max_children = 5`, после — `24`**.
 *
 * **Чего эта проверка НЕ доказывает.** Она читает рецепт, а не считает процессы
 * в живом контейнере: пройдёт и в том случае, если запись на сборке отказала бы
 * молча. Поэтому пункт Б4 листа приёмки остаётся за человеком и снимается
 * изнутри контейнера на боевом — так в самом листе и сказано, ровно из этих
 * соображений. Здесь закрыто другое, и оно тоже нужно: **строку не выкинут при
 * следующей правке Dockerfile**, а число не разъедется на два образа.
 */
class ReleaseImageCarriesTheWorkerCountTest extends TestCase
{
    public function test_the_release_image_writes_the_pool_config_at_build_time(): void
    {
        $dockerfile = $this->backendFile('Dockerfile.release');
        $release = $this->backendFile('Dockerfile');

        if ($dockerfile === null || $release === null) {
            $this->markTestSkipped('Рядом нет каталога backend с образами.');
        }

        $this->assertStringContainsString('portal-entrypoint.sh --write-only', $dockerfile,
            'Релизный образ не пишет настройку пула на сборке: на запуске он работает от www-data, '
            .'каталог пулов ему не принадлежит, и php-fpm останется с умолчанием образа — пять процессов.');

        // Число — в скрипте, и только там. Вписанное вторым местом, оно разойдётся
        // молча: правят одно, а работает другое, и заметно это только под нагрузкой.
        //
        // Комментарии снимаем до поиска, иначе сторож краснеет на объяснении,
        // которое сам же и требует: в шапке той правки числа названы нарочно —
        // «до правки пять, после двадцать четыре». Сторож, кричащий на
        // невиновного, — это выключенный сторож.
        $instructions = (string) preg_replace('/^\s*#.*$/mu', '', $dockerfile);

        $this->assertStringNotContainsString('max_children', $instructions,
            'Число рабочих процессов вписано в Dockerfile: у него уже есть источник — docker/portal-entrypoint.sh.');
    }

    public function test_a_build_that_cannot_write_the_config_fails_instead_of_shipping_five(): void
    {
        $script = $this->backendFile('docker/portal-entrypoint.sh');

        if ($script === null) {
            $this->markTestSkipped('Рядом нет каталога backend.');
        }

        $this->assertStringContainsString('--write-only', $script,
            'Скрипт не умеет «только записать» — значит релизный образ зовёт его как-то иначе.');

        // На запуске отказ записи терпим (этим же образом гоняются тесты, и ронять
        // прогон из-за настройки, которая тестам не нужна, хуже самой настройки).
        // На сборке — не терпим: образ уехал бы с пятью процессами и молча.
        $this->assertMatchesRegularExpression('/elif \[ -n "\$WRITE_ONLY" \];\s*then(?:(?!fi).)*exit 1/su', $script,
            'Отказ записи при сборке не роняет сборку: образ уедет с умолчанием php:fpm, и узнают об этом на боевом.');
    }

    /**
     * Файл из каталога `backend`.
     *
     * `base_path()` и есть этот каталог при обоих способах прогона — и когда
     * смонтирован один `backend/`, и когда всё дерево. Проверка от этого не
     * зависит и не пропускается там, где могла бы работать.
     */
    private function backendFile(string $path): ?string
    {
        $full = base_path($path);

        return is_readable($full) ? (string) file_get_contents($full) : null;
    }
}
