<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Число не называется там, где его не из чего посчитать.
 *
 * В кабинете студента стояло «Присутствие: 0/0» при полном отсутствии данных
 * посещаемости — занятий у студента ещё не было вовсе, и ноль из нуля читается как
 * «ни разу не пришёл». Это хуже отсутствия подписи: отсутствие видно как отсутствие,
 * а ноль выглядит посчитанным. Средний балл в том же кабинете при пустых оценках
 * честно рисует прочерк, и это образец, взятый с соседней карточки того же экрана.
 *
 * Проверка читает исходник, а не считает: подпись обязана приходить из хранилища,
 * где стоит условие, а не собираться прямо в шаблоне. Собранная в шаблоне, она снова
 * напечатает ноль, и заметить это можно будет только глазами на пустой базе.
 */
class NoNumberWhereNothingWasCountedTest extends TestCase
{
    public function test_the_attendance_line_is_not_assembled_in_the_template(): void
    {
        $page = base_path('../frontend/src/pages/student/StudentCabinetPage.vue');
        $store = base_path('../frontend/src/stores/mobileStudent.js');

        if (! is_file($page) || ! is_file($store)) {
            $this->markTestSkipped('Рядом нет каталога frontend: проверка идёт в полном дереве, как в CI.');
        }

        $template = file_get_contents($page);

        $this->assertStringContainsString('store.attendanceLine', $template,
            'подпись посещаемости берётся из хранилища');
        $this->assertStringNotContainsString('Присутствие: ${', $template,
            'и не собирается в шаблоне: там ей негде спросить, есть ли что считать');

        $this->assertMatchesRegularExpression('/attendanceTotal\.value === 0\s*\n?\s*\?/u', file_get_contents($store),
            'в хранилище стоит условие «нечего считать — не называть числа»');
    }

    /**
     * Нагрузка преподавателя: непосчитанное покрытие не показывается нулём.
     *
     * Второй случай того же рода и хуже первого. Преподавателю, открывшему **свою**
     * нагрузку, покрытие часов не запрашивается вовсе — `includeCoverage: !isOwnView`, —
     * а экран рисовал «Назначено 0, Остаток 0, Превышение 0» рядом с честным «План 72».
     * Читается это как «часов не назначено», то есть как утверждение о его работе.
     *
     * Настоящий ноль при этом обязан остаться нулём: покрытие пришло и в нём ноль — так и
     * пишем. Разница ровно между «посчитали и вышло ноль» и «не считали».
     */
    public function test_coverage_that_was_never_asked_for_is_not_shown_as_zero(): void
    {
        $page = base_path('../frontend/src/pages/teaching-load/TeachingLoadPage.vue');

        if (! is_file($page)) {
            $this->markTestSkipped('Рядом нет каталога frontend: проверка идёт в полном дереве, как в CI.');
        }

        $template = file_get_contents($page);

        foreach (['assigned_hours', 'unassigned_hours', 'overassigned_hours'] as $field) {
            $this->assertStringNotContainsString("coverage?.{$field} ?? 0", $template,
                "«{$field}» подставляет ноль там, где покрытие не запрашивалось");
        }

        $this->assertStringContainsString("coverageValue('assigned_hours')", $template,
            'числа покрытия берутся через общее место, где стоит различение');
        $this->assertStringContainsString("store.coverage ? store.coverage[field] ?? 0 : '—'", $template,
            'и это различение — «не спрашивали» против «посчитали и вышло ноль»');
    }
}
