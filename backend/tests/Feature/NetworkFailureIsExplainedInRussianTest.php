<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Запрос, который не дошёл, объясняется по-русски.
 *
 * Ошибки **ответа** портал переводит давно — `request` в `services/api.js`
 * разбирает `errors`, `message` и отдельно 401. Но отказ **сети** случается
 * раньше ответа: `fetch` отклоняется, до разбора дело не доходит, и наружу
 * уходило браузерное `Failed to fetch`. Замер 03.09.2026 обрывом запроса: все
 * семь экранов учебного процесса показывали именно эту строку — портал говорил
 * по-английски ровно тогда, когда человек меньше всего понимает, что случилось.
 *
 * Место одно — `authFetch`, — и теряется оно тоже в одном: достаточно снять
 * `try`, и английская строка вернётся на все экраны разом, не покраснев ни в
 * сборке, ни в прогоне. Поэтому сторож есть, хотя охраняет он несколько строк.
 *
 * **Чего он не проверяет:** что именно увидит человек. Это видно только в
 * браузере, обходом с обрывом запроса — `asking-failed-study.mjs` рядом с
 * `look.js` на DEV. Фронтенд-тестов в репозитории нет вовсе.
 *
 * **Оговорка про отмену.** Перевод верен, пока `fetch` отклоняется только от
 * потери связи. Отменённых запросов в портале нет — ни одного
 * `AbortController`, ни одного `signal:` (проверено поиском 03.09.2026), и это
 * закреплено ниже: если отмена появится, сторож покраснеет и потребует
 * различить `cause.name === 'AbortError'`, а не молча выдавать отмену за
 * потерю связи.
 */
class NetworkFailureIsExplainedInRussianTest extends TestCase
{
    private function apiSource(): string
    {
        $path = base_path('../frontend/src/services/api.js');

        if (! is_readable($path)) {
            $this->markTestSkipped('фронтенд не смонтирован: services/api.js недоступен');
        }

        return (string) file_get_contents($path);
    }

    public function test_a_request_that_never_arrived_speaks_russian(): void
    {
        $source = $this->apiSource();

        $this->assertMatchesRegularExpression(
            '~async function authFetch\([^)]*\)\s*\{\s*try\s*\{~s',
            $source,
            'authFetch больше не ловит отказ сети: на экран вернётся английское «Failed to fetch».',
        );

        $this->assertStringContainsString(
            'Не удалось связаться с порталом',
            $source,
            'Отказ сети снова объясняется не по-русски.',
        );
    }

    public function test_no_request_is_cancelled_by_the_portal_itself(): void
    {
        $root = realpath(base_path('../frontend/src'));

        if ($root === false) {
            $this->markTestSkipped('фронтенд не смонтирован: каталог исходников недоступен');
        }

        $cancelling = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($walk as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['vue', 'js'], true)) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $source = (string) preg_replace('~/\*.*?\*/~s', '', $source);
            $source = (string) preg_replace('~^\s*//.*$~m', '', $source);

            if (preg_match('~\bAbortController\b|\bsignal\s*:~', $source)) {
                $cancelling[] = str_replace('\\', '/', ltrim(str_replace((string) realpath(base_path('../')), '', $file->getPathname()), '/\\'));
            }
        }

        $this->assertSame([], $cancelling, implode("\n", array_merge(
            ['Портал начал отменять запросы сам:'],
            $cancelling,
            ['', 'Пока отмен не было, отказ `fetch` означал ровно потерю связи, и `authFetch` так его и объясняет.',
                'Теперь отмену надо отличать — `cause.name === "AbortError"`, — иначе человеку скажут «нет связи» там,',
                'где связь есть и запрос отменил сам портал.'],
        )));
    }
}
