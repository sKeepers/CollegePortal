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
}
