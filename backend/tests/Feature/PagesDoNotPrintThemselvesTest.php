<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Страница не печатает саму себя.
 *
 * Пока окно открыто, Quasar держит `body` в `position: fixed` (класс
 * `q-body--prevent-scroll`), у документа не остаётся потока, и печать страницы
 * даёт ровно один лист. Замерено 29.08.2026: 27 карточек паролей — одна
 * страница, на ней 21; тот же замер со снятым классом и ничем больше — две.
 *
 * Опаснее самой потери то, что лист выходит **правдоподобным**: правила
 * `@media print { body * { visibility: hidden } }` прячут приложение, и на
 * бумаге оказывается чистая ведомость, у которой просто нет хвоста. Владелец
 * увидел это только потому, что знал число выданных карточек.
 *
 * Печатать надо отдельным документом — `printHtmlDocument` из
 * `frontend/src/utils/print.js`: туда каскад приложения и замок `body` не
 * приходят вовсе.
 */
class PagesDoNotPrintThemselvesTest extends TestCase
{
    private function pages(): array
    {
        $root = realpath(base_path('../frontend/src/pages'));

        if ($root === false) {
            $this->markTestSkipped('фронтенд не смонтирован: каталог страниц недоступен');
        }

        $files = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($walk as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        $this->assertNotEmpty($files, 'страниц не найдено — сторож смотрит не туда');

        return $files;
    }

    private function shortName(string $file): string
    {
        $root = (string) realpath(base_path('../'));

        return ltrim(str_replace($root, '', $file), '/');
    }

    /**
     * Стили самого файла, без того, что страница собирает строкой.
     *
     * Внутри `<script setup>` живут шаблонные строки печатных документов, и в
     * них `@media print` и `<style>` стоят законно: это стили **другого**
     * документа. Сторож, который не отличает одно от другого, покраснеет на
     * правильном коде — а такого сторожа отключают.
     */
    private function styleBlocks(string $source): array
    {
        $withoutScripts = preg_replace('/<script\b[^>]*>.*?<\/script>/su', '', $source) ?? '';

        preg_match_all('/<style\b[^>]*>(.*?)<\/style>/su', $withoutScripts, $matches);

        return $matches[1] ?? [];
    }

    private function withoutComments(string $source): string
    {
        $source = preg_replace('/\/\*.*?\*\//su', '', $source) ?? $source;
        $source = preg_replace('/<!--.*?-->/su', '', $source) ?? $source;

        // Строчные комментарии — но не `//` внутри адресов вроде `https://`.
        return preg_replace('~(?<!:)//[^
]*~u', '', $source) ?? $source;
    }

    public function test_no_page_hides_the_application_to_print_itself(): void
    {
        $guilty = [];

        foreach ($this->pages() as $file) {
            foreach ($this->styleBlocks((string) file_get_contents($file)) as $style) {
                if (! str_contains($style, '@media print')) {
                    continue;
                }

                if (preg_match('/body\s*\*/u', $style) === 1) {
                    $guilty[] = $this->shortName($file);
                }
            }
        }

        $this->assertSame([], $guilty, implode("\n", [
            'Страница прячет приложение, чтобы напечатать себя: '.implode(', ', $guilty).'.',
            'Так печатать нельзя: при открытом окне `body` фиксирован, потока у документа нет,',
            'и на бумагу попадает только первый лист — а выглядит он целым.',
            'Собирайте отдельный документ через `printHtmlDocument` из `frontend/src/utils/print.js`.',
        ]));
    }

    public function test_no_page_calls_window_print(): void
    {
        $guilty = [];

        foreach ($this->pages() as $file) {
            $source = $this->withoutComments((string) file_get_contents($file));

            if (preg_match('/window\s*\.\s*print\s*\(/u', $source) === 1) {
                $guilty[] = $this->shortName($file);
            }
        }

        $this->assertSame([], $guilty, implode("\n", [
            '`window.print()` печатает саму страницу: '.implode(', ', $guilty).'.',
            'Печать самой страницы теряет всё ниже первого листа, когда открыто окно.',
            'Нужен отдельный документ: `printHtmlDocument` или `printPage` из `frontend/src/utils/print.js`.',
        ]));
    }
}
