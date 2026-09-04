<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Экраны учебного процесса отличают «данных нет» от «спросить не удалось».
 *
 * Что охраняется, словами. Пока экран этого не различает, любая поломка ниже
 * превращается в **утверждение о колледже**, а рядом встаёт число, которого
 * никто не считал. Замер 03.09.2026 в браузере — каждый экран открыт дважды,
 * обычным заходом и с оборванным запросом его собственного списка:
 *
 *   нагрузка       «Нагрузка не найдена. Создайте нагрузку или сформируйте её
 *                  из учебного плана» и «Найдено нагрузок: 0»
 *   экзамены       «Экзамены не найдены. Создайте экзамен или импортируйте CSV»
 *                  и «Найдено экзаменов: 0»
 *   учебные планы  «Учебные планы не найдены» и «Найдено учебных планов: 0»
 *   журнал         «Данные журнала не найдены» и «Занятий: 0; не заполнено: 0;
 *                  ожидают подписи: 0» — три числа, ни одного посчитанного
 *   посещаемость   «Нет данных для анализа. Добавьте расписание и события
 *                  проходной» — совет о колледже, которого не спрашивали
 *   итоговые       «Выберите группу и дисциплину» — а выбрать было не из чего,
 *                  списки групп и дисциплин не пришли
 *
 * **Чего этот сторож не проверяет, и это важно знать заранее.** Он проверяет
 * **проводку**, а не поведение: что признак «список получен» заведён в
 * хранилище и что экран его спрашивает. Поведение проверяется обходом с
 * обрывом запроса — `/home/andale/.cp-screens/asking-failed-study.mjs` на
 * DEV, — и иначе его не проверить: тестов фронтенда в репозитории нет вовсе.
 * Значит сторож ловит только снятие признака при правках, но именно это и
 * случается: строку `store.loaded` легко потерять, переписывая шаблон, и
 * потеря не видна ни в сборке, ни в прогоне.
 *
 * Образец взят у экранов проходной (`053103511`) намеренно: делать то же
 * самое третьим способом — и есть третий способ, а не единообразие.
 */
class StudyScreensTellEmptyFromFailedTest extends TestCase
{
    /** хранилище => экран, который обязан спрашивать признак */
    private const WIRED = [
        'frontend/src/stores/teachingLoad.js' => 'frontend/src/pages/teaching-load/TeachingLoadPage.vue',
        'frontend/src/stores/exams.js' => 'frontend/src/pages/exams/ExamsPage.vue',
        'frontend/src/stores/curricula.js' => 'frontend/src/pages/curricula/CurriculaPage.vue',
        'frontend/src/stores/journal.js' => 'frontend/src/pages/journal/JournalPage.vue',
        'frontend/src/stores/attendanceAnalysis.js' => 'frontend/src/pages/attendance/AttendancePage.vue',
    ];

    private function source(string $path): string
    {
        $full = base_path('../'.$path);

        if (! is_file($full)) {
            $this->markTestSkipped('фронтенд не смонтирован: '.$path.' недоступен');
        }

        return (string) file_get_contents($full);
    }

    public function test_every_study_list_knows_whether_it_was_received(): void
    {
        $missing = [];

        foreach (self::WIRED as $store => $page) {
            $storeSource = $this->source($store);

            if (! str_contains($storeSource, 'const loaded = ref(false)')) {
                $missing[] = $store.' — признак «список получен» не заведён';
            }

            if (! preg_match('~\bloaded\.value = true~', $storeSource)) {
                $missing[] = $store.' — признак нигде не поднимается: он останется ложным навсегда';
            }

            if (! preg_match('~\bloaded\.value = false~', $storeSource)) {
                $missing[] = $store.' — признак нигде не сбрасывается: после одной удачной загрузки отказ станет неотличим от пустоты';
            }

            if (! preg_match('~\bloaded\b\s*,~', substr($storeSource, (int) strrpos($storeSource, 'return {'))) ) {
                $missing[] = $store.' — признак не возвращается наружу, экран его не увидит';
            }

            if (! str_contains($this->source($page), 'store.loaded')) {
                $missing[] = $page.' — экран не спрашивает признак и снова скажет «данных нет» на отказ запроса';
            }
        }

        $this->assertSame([], $missing, implode("\n", array_merge(
            ['Экран снова не отличит «данных нет» от «спросить не удалось»:'],
            $missing,
            ['', 'Проверить поведение: /home/andale/.cp-screens/asking-failed-study.mjs на DEV.'],
        )));
    }

    /**
     * Отказ сети объясняется по-русски.
     *
     * Ошибки ответа портал переводит давно, а отказ **сети** случается раньше
     * ответа и уходил сырым: все семь экранов показывали браузерное
     * `Failed to fetch` — по-английски и ровно тогда, когда человек меньше
     * всего понимает, что случилось. Лечится в одном месте, `authFetch`, и
     * теряется тоже в одном.
     */
    public function test_a_request_that_never_arrived_is_explained_in_russian(): void
    {
        $source = $this->source('frontend/src/services/api.js');

        $this->assertMatchesRegularExpression(
            '~async function authFetch\(.*?\)\s*\{\s*try\s*\{~s',
            $source,
            'authFetch больше не ловит отказ сети: на экран вернётся английское «Failed to fetch».',
        );

        $this->assertStringContainsString(
            'Не удалось связаться с порталом',
            $source,
            'Отказ сети снова объясняется не по-русски.',
        );
    }
}
