<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Ни одно хранилище не просит список преподавателей кусочком.
 *
 * 28.08.2026 преподавателей стало 177 вместо четырёх, и все справочники разом
 * оборвались на двадцатом по алфавиту: выбор преподавателя в расписании, в
 * нагрузке, у дисциплины, у группы. Хуже справочников был кабинет —
 * `TeacherDashboard` ищет вошедшего среди присланных строк, и преподаватель за
 * двадцатым себя не находил: свой журнал и свою нагрузку он видел пустыми.
 *
 * Нашлось это глазами: экран говорил «1 - 20 из 20» при 177 в базе. Сборка
 * проходила, прогон молчал — оба были правы, дефект ждал данных.
 *
 * **Серверная половина закрыта не здесь.** `PageSize::from` и
 * `ListsHonourPageSizeTest` — общее место для всех списков портала, и
 * повторять его тут нечем. Здесь остаётся то, чего оно не видит: как список
 * просят с той стороны. Проверка читает каталог хранилищ целиком, а не
 * перечисляет файлы: новое хранилище вернуло бы ту же беду молча.
 */
class TeachersListIsWholeTest extends TestCase
{
    public function test_no_store_asks_for_teachers_a_page_at_a_time(): void
    {
        $directory = base_path('../frontend/src/stores');

        if (! is_dir($directory)) {
            $this->markTestSkipped('Рядом нет каталога frontend: проверка идёт в полном дереве, как в CI.');
        }

        $offenders = [];
        $seen = 0;

        foreach (glob($directory.'/*.js') as $file) {
            foreach (file($file) as $number => $line) {
                // `listAll` берёт список целиком сам — это правильный вызов, и он
                // сюда не попадает: подстроки `api.list('teachers'` в нём нет.
                $whole = str_contains($line, "api.listAll('teachers'");
                $page = str_contains($line, "api.list('teachers'");

                if (! $whole && ! $page) {
                    continue;
                }

                $seen++;

                if ($page && ! str_contains($line, 'per_page')) {
                    $offenders[] = basename($file).':'.($number + 1);
                }
            }
        }

        $this->assertGreaterThan(0, $seen, 'Ни одного вызова списка преподавателей не найдено — разбор сломался');
        $this->assertSame([], $offenders, 'Список преподавателей просят страницей: '.implode(', ', $offenders));
    }
}
