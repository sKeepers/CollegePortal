<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Раздел открывается списком, а не чужой карточкой.
 *
 * Дважды за двое суток одно и то же: «Выпускники и дипломы» открывались
 * карточкой постороннего человека (28.08.2026), «Цифровые пропуска» — тоже
 * (29.08.2026, нашёл владелец). Оба раза это не память о выборе, а «всегда
 * первый»: заходящий видит чужую фамилию, у пропусков ещё QR-код и токен, а в
 * адресной строке стоит чужой идентификатор.
 *
 * После первого случая мы решили, что он единичный. Он не был.
 *
 * Сторож обходит страницы сам. Список ниже — **исключения**, и у каждого
 * записана причина: новый раздел с таким поведением обязан либо перестать так
 * делать, либо появиться в этом списке с объяснением. Это и есть смысл: решение
 * принимается один раз и остаётся написанным.
 */
class SectionsOpenAsListsTest extends TestCase
{
    /**
     * Где выбор первой строки сделан осознанно.
     *
     * Общее у всех: за строкой не стоит человек. Роль, настройка, учебный план
     * или пакет — не чья-то личная карточка, и открывать первый по счёту там
     * удобно, а не опасно.
     *
     * @var array<string, string>
     */
    private const DELIBERATE = [
        'RolesPage.vue' => 'справочник ролей: короткий список, личных данных нет',
        'SettingsPage.vue' => 'выбирается вкладка настроек, а не запись',
        'UniversalImportPage.vue' => 'выбирается вид данных для загрузки, а не строка',
        'CurriculaPage.vue' => 'учебный план, мастер-деталь над справочником',
        'TeachingLoadPage.vue' => 'выбор справочного значения, а не карточки человека',
        'ExamsPage.vue' => 'экзамен, а не человек',
        'FisPage.vue' => 'пакет выгрузки, а не человек',
        'FrdoPage.vue' => 'пакет выгрузки, а не человек',
        'AuditPage.vue' => 'запись журнала действий: то же самое видно и в списке',
        'MobileCuratorHomePage.vue' => 'куратор попадает в свою группу — это его группа, а не чужая',
    ];

    public function test_no_section_opens_on_somebody_elses_card(): void
    {
        $root = base_path('../frontend/src/pages');

        if (! is_dir($root)) {
            // Дерево смонтировано без фронтенда. Проверять нечего, и молчать об
            // этом нельзя — но и падать тоже: рецепт прогона такой у всех.
            $this->addToAssertionCount(1);

            return;
        }

        $guilty = [];
        $walker = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($walker as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'vue') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $start = strpos($source, 'onMounted(');

            if ($start === false) {
                continue;
            }

            // Хвост от `onMounted(` — с запасом на несколько строк тела.
            $body = substr($source, $start, 700);

            // Выбор первого по счёту: `something[0]` рядом с выбором.
            if (preg_match('/\[0\]/', $body) !== 1) {
                continue;
            }

            if (preg_match('/select|selected|activeTab|dataType|replace\(/i', $body) !== 1) {
                continue;
            }

            $name = $file->getFilename();

            if (array_key_exists($name, self::DELIBERATE)) {
                continue;
            }

            $guilty[] = $name;
        }

        $this->assertSame([], array_values(array_unique($guilty)), implode("\n", array_merge(
            ['Эти разделы открываются первой строкой. Если за строкой стоит человек, заходящий видит чужие данные:'],
            $guilty,
            ['Либо не открывайте карточку сами, либо впишите раздел в `DELIBERATE` с причиной.'],
        )));
    }

    public function test_the_list_of_deliberate_sections_still_matches_the_tree(): void
    {
        // Список исключений стареет молча: раздел переименовали или удалили, а
        // строка осталась и прикрывает уже другое. Проверяем, что каждый файл
        // из списка ещё существует.
        $root = base_path('../frontend/src/pages');

        if (! is_dir($root)) {
            $this->addToAssertionCount(1);

            return;
        }

        $present = [];
        $walker = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($walker as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $present[$file->getFilename()] = true;
            }
        }

        $missing = array_values(array_diff(array_keys(self::DELIBERATE), array_keys($present)));

        $this->assertSame([], $missing, 'В списке осознанных исключений остались страницы, которых больше нет: '.implode(', ', $missing));
    }
}
