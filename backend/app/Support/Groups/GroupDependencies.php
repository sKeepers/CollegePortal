<?php

namespace App\Support\Groups;

use Illuminate\Support\Facades\DB;

/**
 * Что ссылается на учебную группу.
 *
 * Нужно перед удалением группы. На `groups` семь внешних ключей с
 * `ON DELETE CASCADE` — студенты, экзамены, занятия журнала, расписание в двух
 * таблицах, шаблоны расписания и позиции нагрузки — и три с `SET NULL`.
 *
 * **Итоговые оценки за семестр стоят здесь не ради каскада, а ради отказа, и разница
 * между двумя правилами удаления тут решающая.** Каскад уносит строку целиком — потеря
 * заметна сразу, её видно по пустому списку. `SET NULL` **оставляет строку и стирает
 * смысл**: ведомость никуда не денется, а группа, в которой оценку ставили, исчезнет,
 * и со стороны всё будет выглядеть исправным.
 *
 * Из этих оценок собирается приложение к диплому. Значит удаление группы молча
 * испортило бы документ, который человек получает на всю жизнь, а заметили бы это на
 * выпуске — в 2027 году, когда восстанавливать будет не из чего. Поэтому портал обязан
 * отказать в удалении и назвать причину, а не занулить колонку.
 * Удалить группу, на которую ещё кто-то ссылается, значит молча снести
 * половину учебного года: база сделает это без единого предупреждения.
 *
 * Поэтому «группа пустая» проверяется **запросом**, а не выводится из того, что
 * студентов только что перенесли.
 *
 * Список таблиц закреплён тестом `GroupDependenciesAreKnownTest`: он читает
 * внешние ключи из самой схемы и падает, если появился ключ, которого здесь нет.
 * Без этого проверка однажды станет неправдой и не скажет об этом.
 */
final class GroupDependencies
{
    /** @var array<string, string> таблица → колонка со ссылкой на группу */
    public const TABLES = [
        'students' => 'group_id',
        'exams' => 'group_id',
        'journal_lessons' => 'group_id',
        'schedule_entries' => 'group_id',
        'schedule_lessons' => 'group_id',
        'schedule_templates' => 'group_id',
        'teaching_load_items' => 'group_id',
        'graduates' => 'group_id',
        'teaching_loads' => 'group_id',
        'semester_grades' => 'group_id',
    ];

    /**
     * Что осталось висеть на группе.
     *
     * @return array<string, int> таблица → сколько строк; пустой массив, если ничего
     */
    public static function leftovers(int $groupId): array
    {
        $left = [];

        foreach (self::TABLES as $table => $column) {
            $count = DB::table($table)->where($column, $groupId)->count();

            if ($count > 0) {
                $left[$table] = $count;
            }
        }

        return $left;
    }

    /** Человеческое перечисление для сообщения об отказе. */
    public static function describe(array $leftovers): string
    {
        return implode(', ', array_map(
            fn (string $table, int $count): string => sprintf('%s (%d)', $table, $count),
            array_keys($leftovers),
            array_values($leftovers),
        ));
    }
}
