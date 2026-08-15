<?php

namespace App\Support\Notifications;

/**
 * Каталог событий — то, что человек видит галочками в «Моей учётной записи».
 *
 * Состав взят из разбора `NOTIFY_001_PLAN.md` и **будет меняться**: поэтому подписки
 * лежат строками в таблице, а не колонками, и добавление галочки не требует миграции.
 *
 * `audience` — кому галочка показывается. Это подсказка интерфейсу, а не право: студенту
 * незачем видеть «Незакрытый журнал», но и вреда от подписки на него нет — событий для
 * него просто не возникнет.
 *
 * Реализованные события помечены `ready`. Непомеченные показываются как «скоро»: обещать
 * галочкой то, чего портал ещё не отправляет, значит один раз потерять доверие ко всем
 * остальным.
 */
final class NotificationEvents
{
    public const LESSONS_TOMORROW = 'lessons.tomorrow';

    public const SCHEDULE_CHANGED = 'schedule.changed';

    public const GRADES_DAILY = 'grades.daily';

    public const ATTENDANCE_DAILY = 'attendance.daily';

    public const EXAMS = 'exams';

    public const DEBTS = 'debts';

    public const JOURNAL_UNCLOSED = 'journal.unclosed';

    /** @return list<array{code: string, name: string, hint: string, audience: list<string>, ready: bool}> */
    public static function all(): array
    {
        return [
            [
                'code' => self::LESSONS_TOMORROW,
                'name' => 'Занятия на завтра',
                'hint' => 'Одним сообщением вечером.',
                'audience' => ['student', 'teacher'],
                'ready' => true,
            ],
            [
                'code' => self::SCHEDULE_CHANGED,
                'name' => 'Изменения в расписании',
                'hint' => 'Сразу, как расписание поправили.',
                'audience' => ['student', 'teacher'],
                'ready' => false,
            ],
            [
                'code' => self::GRADES_DAILY,
                'name' => 'Новые оценки',
                'hint' => 'Сводкой за день, вечером.',
                'audience' => ['student'],
                'ready' => true,
            ],
            [
                'code' => self::ATTENDANCE_DAILY,
                'name' => 'Пропуски и опоздания',
                'hint' => 'Сводкой за день. О присутствии не сообщаем — только о том, что придётся объяснять.',
                'audience' => ['student'],
                'ready' => true,
            ],
            [
                'code' => self::EXAMS,
                'name' => 'Экзамены и зачёты',
                'hint' => 'За сутки до и по результату.',
                'audience' => ['student'],
                'ready' => false,
            ],
            [
                'code' => self::DEBTS,
                'name' => 'Задолженности',
                'hint' => 'Нет оценки к концу периода или несданный экзамен.',
                'audience' => ['student'],
                'ready' => false,
            ],
            [
                'code' => self::JOURNAL_UNCLOSED,
                'name' => 'Незакрытый журнал',
                'hint' => 'Утром следующего дня, если за вчера остались занятия без отметок.',
                'audience' => ['teacher'],
                'ready' => false,
            ],
        ];
    }

    public static function exists(string $code): bool
    {
        return in_array($code, array_column(self::all(), 'code'), true);
    }

    /** @return list<string> */
    public static function readyCodes(): array
    {
        return array_values(array_column(array_filter(self::all(), static fn (array $event): bool => $event['ready']), 'code'));
    }
}
