<?php

namespace App\Services;

use App\Models\Group;
use App\Models\JournalGrade;
use App\Models\JournalLesson;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Успеваемость группы: оценки журнала, средний балл, кто без оценок.
 *
 * **Считается по `journal_grades`** — по тем оценкам, которые преподаватель
 * ставит сегодня, в том числе из мобильного кабинета. Старая таблица `grades`
 * живёт своей жизнью: в неё пишут только устаревший `POST api/grades` и
 * демонстрационный набор, зеркала между ними нет ни в одну сторону. Отчёт
 * `reports/grades-by-group` читает как раз старую, поэтому его цифры и цифры
 * куратора могут разойтись. Расхождение известно и записано на доске; сведение
 * двух таблиц — отдельная работа и не этой области.
 *
 * Средний балл считается ровно так же, как в том отчёте: простое среднее
 * числовых оценок, округление до сотых, вес не учитывается. Это сделано
 * намеренно — две картины одной успеваемости не должны расходиться ещё и
 * способом счёта.
 *
 * Зачёты, «освобождён» и «не аттестован» в среднее не входят и показываются
 * отдельным счётчиком: группа, где вместо оценок стоят зачёты, не должна
 * выглядеть неуспевающей.
 */
class StudentPerformanceService
{
    /** Сколько последних оценок студента показывается в строке. */
    private const RECENT_GRADES = 5;

    public function __construct(private readonly GroupRosterService $roster)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function forGroup(Group $group, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $lessons = JournalLesson::query()
            ->where('group_id', $group->id)
            ->when($dateFrom, fn (Builder $query, string $date) => $query->whereDate('lesson_date', '>=', $date))
            ->when($dateTo, fn (Builder $query, string $date) => $query->whereDate('lesson_date', '<=', $date))
            ->get(['id', 'subject_id', 'lesson_date'])
            ->keyBy('id');

        // Оценки берутся только у занятий этой группы за период: пустой список
        // занятий обязан дать ноль оценок, а не потерянный фильтр.
        $grades = $lessons->isEmpty()
            ? collect()
            : JournalGrade::query()
                ->whereIn('journal_lesson_id', $lessons->keys()->all())
                ->whereNotNull('value')
                ->where('value', '!=', '')
                ->orderBy('marked_at')
                ->get(['id', 'journal_lesson_id', 'student_id', 'value', 'marked_at']);

        $subjectNames = Subject::query()
            ->whereIn('id', $lessons->pluck('subject_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        $byStudent = $grades->groupBy('student_id');

        $roster = $this->roster->active($group);

        $students = $roster
            ->map(fn (Student $student): array => $this->studentRow(
                $student,
                $byStudent->get($student->id, collect()),
                $lessons,
                $subjectNames,
            ))
            ->values();

        // Дальше считается только по действующему составу: оценки отчисленного
        // остаются в журнале, но в успеваемости группы им не место — иначе
        // средний балл не сойдётся со списком, который куратор видит рядом.
        $rosterGrades = $grades->whereIn('student_id', $roster->pluck('id')->all());

        return [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'course' => $group->course,
            ],
            'period' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'summary' => $this->summary($students, $rosterGrades, $lessons->count()),
            'students' => $students->all(),
            'subjects' => $this->subjectRows($rosterGrades, $lessons, $subjectNames),
        ];
    }

    /**
     * @param  Collection<int, JournalGrade>  $grades
     * @param  Collection<int, JournalLesson>  $lessons
     * @param  Collection<int, string>  $subjectNames
     * @return array<string, mixed>
     */
    private function studentRow(Student $student, Collection $grades, Collection $lessons, Collection $subjectNames): array
    {
        $values = $grades->pluck('value');
        $numeric = $this->numeric($values);

        return [
            'id' => $student->id,
            'name' => collect([$student->last_name, $student->first_name, $student->middle_name])->filter()->join(' '),
            'grades_count' => $values->count(),
            'numeric_grades_count' => $numeric->count(),
            'average_grade' => $this->average($numeric),
            // Двойка — не то же самое, что низкий средний балл: одна за семестр
            // теряется в среднем, а куратору она и нужна.
            'failing_count' => $values->filter(fn (string $value): bool => $value === '2')->count(),
            'not_graded_count' => $values->filter(fn (string $value): bool => ! is_numeric($value))->count(),
            'has_grades' => $values->isNotEmpty(),
            'recent' => $grades
                ->sortByDesc(fn (JournalGrade $grade): string => (string) ($lessons->get($grade->journal_lesson_id)?->lesson_date?->toDateString() ?? ''))
                ->take(self::RECENT_GRADES)
                ->map(fn (JournalGrade $grade): array => [
                    'value' => $grade->value,
                    'date' => $lessons->get($grade->journal_lesson_id)?->lesson_date?->toDateString(),
                    'subject' => $subjectNames->get($lessons->get($grade->journal_lesson_id)?->subject_id),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $students
     * @param  Collection<int, JournalGrade>  $grades
     * @return array<string, mixed>
     */
    private function summary(Collection $students, Collection $grades, int $lessonsCount): array
    {
        // Среднее по группе считается по самим оценкам, а не по средним
        // студентов: среднее средних завышает вклад того, у кого оценок две.
        $groupAverage = $this->average($this->numeric($grades->pluck('value')));

        return [
            'students_count' => $students->count(),
            'lessons_count' => $lessonsCount,
            'grades_count' => (int) $students->sum('grades_count'),
            'numeric_grades_count' => (int) $students->sum('numeric_grades_count'),
            'average_grade' => $groupAverage,
            'without_grades' => $students->filter(fn (array $student): bool => ! $student['has_grades'])->count(),
            'with_failing' => $students->filter(fn (array $student): bool => $student['failing_count'] > 0)->count(),
        ];
    }

    /**
     * @param  Collection<int, JournalGrade>  $grades
     * @param  Collection<int, JournalLesson>  $lessons
     * @param  Collection<int, string>  $subjectNames
     * @return list<array<string, mixed>>
     */
    private function subjectRows(Collection $grades, Collection $lessons, Collection $subjectNames): array
    {
        return $grades
            ->groupBy(fn (JournalGrade $grade): int => (int) ($lessons->get($grade->journal_lesson_id)?->subject_id ?? 0))
            ->map(function (Collection $subjectGrades, int $subjectId) use ($subjectNames): array {
                $values = $subjectGrades->pluck('value');
                $numeric = $this->numeric($values);

                return [
                    'id' => $subjectId ?: null,
                    'name' => $subjectNames->get($subjectId) ?? 'Без дисциплины',
                    'grades_count' => $values->count(),
                    'numeric_grades_count' => $numeric->count(),
                    'average_grade' => $this->average($numeric),
                    'failing_count' => $values->filter(fn (string $value): bool => $value === '2')->count(),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, string>  $values
     * @return Collection<int, float>
     */
    private function numeric(Collection $values): Collection
    {
        return $values
            ->filter(fn (?string $value): bool => $value !== null && is_numeric($value))
            ->map(fn (string $value): float => (float) $value)
            ->values();
    }

    /** @param  Collection<int, float>  $numeric */
    private function average(Collection $numeric): ?float
    {
        return $numeric->isEmpty() ? null : round($numeric->avg(), 2);
    }
}
