<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Обработчик, привязанный без скобок, получает событие мыши.
 *
 * `@click="openCreateDialog"` передаёт в функцию `MouseEvent`, а не то, что
 * автор имел в виду. Пока функция параметр не трогает, это безобидно; как
 * только она читает у него поле, нажатие роняет страницу и **ничего не
 * происходит** — ни окна, ни сообщения. Замерено 29.08.2026 на экране
 * расписания: `TypeError: Cannot read properties of undefined (reading
 * 'value')`, диалогов после нажатия ноль. Кнопка «Создать занятие» так стояла
 * мёртвой, и заметить это можно было только нажав.
 *
 * Проверка сужена до опасного случая: привязка без скобок **и** разыменование
 * первого параметра в теле. Обработчик, которому событие безразлично, не
 * трогается — иначе сторож обвинял бы десятки правильных кнопок. На дереве
 * 29.08.2026 признак дал ровно одно совпадение, и это был дефект.
 */
class HandlerGetsTheMouseEventTest extends TestCase
{
    private function withoutComments(string $source): string
    {
        $source = preg_replace('/<!--.*?-->/su', '', $source) ?? $source;
        $source = preg_replace('/\/\*.*?\*\//su', '', $source) ?? $source;

        return preg_replace('~(?<!:)//[^
]*~u', '', $source) ?? $source;
    }

    public function test_no_handler_reads_a_field_of_the_mouse_event(): void
    {
        $root = realpath(base_path('../frontend/src'));

        if ($root === false) {
            $this->markTestSkipped('фронтенд не смонтирован: каталог исходников недоступен');
        }

        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);
        $this->assertNotEmpty($files, 'файлов не найдено — сторож смотрит не туда');

        $guilty = [];

        foreach ($files as $file) {
            // Комментарии снимаются до поиска: пример неправильной привязки
            // стоит в пояснении к самой исправленной функции, и сторож ловил
            // его как дефект. Обвинить того, кто объяснил свою же ошибку, —
            // верный способ, чтобы сторожа выключили.
            $source = $this->withoutComments((string) file_get_contents($file));

            if (preg_match('/<script\b[^>]*>(.*?)<\/script>/su', $source, $scriptMatch) !== 1) {
                continue;
            }

            $script = $scriptMatch[1];

            preg_match_all('/@(?:click|dblclick)="([A-Za-z_$][\w$]*)"/u', $source, $handlers);

            foreach (array_unique($handlers[1] ?? []) as $handler) {
                if (preg_match('/function\s+'.preg_quote($handler, '/').'\s*\(\s*([\w$]+)/u', $script, $declaration, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }

                $parameter = $declaration[1][0];
                $body = substr($script, $declaration[0][1], 700);

                if (preg_match('/\b'.preg_quote($parameter, '/').'\s*[.\[]/u', $body) === 1) {
                    $guilty[] = basename($file).': @click="'.$handler.'" → '.$handler.'('.$parameter.')';
                }
            }
        }

        $this->assertSame([], $guilty, implode("\n", [
            'Обработчик получит событие мыши вместо ожидаемого объекта: '.implode('; ', $guilty).'.',
            'Привязка без скобок передаёт `MouseEvent`, чтение его поля роняет нажатие,',
            'и на экране не происходит ничего — ни окна, ни ошибки для человека.',
            'Пишите `@click="handler()"`, а в самой функции проверяйте форму объекта, а не его наличие.',
        ]));
    }
}
