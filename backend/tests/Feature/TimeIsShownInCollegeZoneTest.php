<?php

namespace Tests\Feature;

use App\Support\Time\CollegeTime;
use Tests\TestCase;

/**
 * Время на экране — время колледжа, а не время того, кто смотрит.
 *
 * Что охраняется, словами. Портал хранит время в UTC и правильно делает. Но
 * рисовал его браузер: 53 вызова показа в 35 файлах, и `timeZone` не был задан
 * **ни в одном**. Значит на экране стоял час машины, а не час колледжа.
 *
 * Замерено 03.09.2026 на трёх машинах, одно и то же событие — проход в 09:15 по
 * колледжу (`2026-09-10T06:15:00Z`):
 *
 * ```
 * UTC              10.09.2026, 06:15
 * America/New_York 10.09.2026, 02:15
 * Asia/Vladivostok 10.09.2026, 16:15
 * ```
 *
 * И календарная дата ломалась в другую сторону: `2026-09-01` в Нью-Йорке
 * показывалась как **31 августа**, а пропущенная через `toLocaleString` получала
 * ещё и выдуманный час — `00:00`, `20:00` и `10:00` на тех же трёх машинах.
 * После сведения к общему форматировщику все три дают `10.09.2026, 09:15` и
 * `01.09.2026`. Разбор — `docs/TIME_ON_PRINTED_DOCUMENTS.md`.
 *
 * **Форм записи две, и сторож, знающий одну, слеп.** Разбор насчитал «37
 * вызовов `toLocale*` в 27 файлах» и не увидел `new Intl.DateTimeFormat` — а их
 * оказалось ещё одиннадцать, в семи файлах, ровно с той же бедой. Поэтому здесь
 * ищутся **обе**, и если появится третья, её надо дописать сюда же.
 *
 * Комментарии снимаются до поиска: в самом `utils/datetime.js` неправильный
 * вызов приведён как пример того, чего делать нельзя, и сторож, краснеющий на
 * объяснении, будет отключён.
 */
class TimeIsShownInCollegeZoneTest extends TestCase
{
    /** Единственное место, где показ времени разрешено собирать руками. */
    private const FORMATTER = 'frontend/src/utils/datetime.js';

    /**
     * @return array<string, string> путь => содержимое без комментариев
     */
    private function sources(): array
    {
        $root = realpath(base_path('../frontend/src'));

        if ($root === false) {
            $this->markTestSkipped('фронтенд не смонтирован: каталог исходников недоступен');
        }

        $files = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($walk as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['vue', 'js'], true)) {
                continue;
            }

            $short = ltrim(str_replace((string) realpath(base_path('../')), '', $file->getPathname()), '/\\');
            $short = str_replace('\\', '/', $short);

            if ($short === self::FORMATTER) {
                continue;
            }

            $files[$short] = $this->withoutComments((string) file_get_contents($file->getPathname()));
        }

        ksort($files);

        $this->assertNotEmpty($files, 'исходников не найдено — сторож смотрит не туда');

        return $files;
    }

    private function withoutComments(string $source): string
    {
        $source = (string) preg_replace('~/\*.*?\*/~s', '', $source);
        $source = (string) preg_replace('~^\s*//.*$~m', '', $source);
        $source = (string) preg_replace('~<!--.*?-->~s', '', $source);

        return $source;
    }

    public function test_no_screen_formats_time_by_the_watching_machine_clock(): void
    {
        $guilty = [];

        foreach ($this->sources() as $path => $source) {
            if (preg_match('~->\s*toLocale(String|DateString|TimeString)\s*\(~', $source)
                || preg_match('~\.\s*toLocale(String|DateString|TimeString)\s*\(~', $source)) {
                $guilty[] = $path.' — toLocale*';
            }

            if (preg_match('~new\s+Intl\.DateTimeFormat~', $source)) {
                $guilty[] = $path.' — Intl.DateTimeFormat';
            }
        }

        $this->assertSame([], $guilty, implode("\n", array_merge(
            ['Время показано часами того, кто смотрит, а не часами колледжа:'],
            $guilty,
            ['', 'Пользуйтесь `formatDate`, `formatDateTime`, `formatTime` из `'.self::FORMATTER.'`:',
                'там пояс задан один раз, и календарная дата не превращается в момент.'],
        )));
    }

    /**
     * Пояс записан в двух местах, и они обязаны совпадать.
     *
     * Сервер режет по нему сутки (`CollegeTime::ZONE`), браузер по нему рисует
     * час. Разойдутся — и отбор «за 22 августа» перестанет совпадать с тем, что
     * человек видит на экране за 22 августа, причём молча: обе половины будут
     * выглядеть исправными по отдельности.
     */
    public function test_the_screen_and_the_server_keep_the_same_college_zone(): void
    {
        $path = base_path('../'.self::FORMATTER);

        if (! is_file($path)) {
            $this->markTestSkipped('фронтенд не смонтирован: общий форматировщик недоступен');
        }

        $source = (string) file_get_contents($path);

        $this->assertTrue(
            (bool) preg_match("~export const COLLEGE_TIME_ZONE = '([^']+)'~", $source, $found),
            'В общем форматировщике не объявлен COLLEGE_TIME_ZONE.',
        );

        $this->assertSame(
            CollegeTime::ZONE,
            $found[1],
            'Пояс на экране разошёлся с поясом сервера: сутки будут резаться не там, где показан час.',
        );
    }
}
