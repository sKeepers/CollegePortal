<?php

namespace App\Services;

use App\Models\CurriculumSubject;
use App\Models\Graduate;
use App\Models\SemesterGrade;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Приложение к диплому, собранное из учебного плана и итоговых оценок.
 *
 * До 24.08.2026 его набирали руками целиком: сорок-пятьдесят строк на выпускника, каждая
 * из которых уже лежит в портале. Так не пользуются — возвращаются в Word, а вместе с ним
 * уходят и проверяемость, и выгрузка в ФРДО.
 *
 * **Дисциплина в приложении одна, а в плане её несколько.** Учебный план ведёт дисциплину
 * по семестрам: «Сольфеджио» может идти три семестра тремя строками. В приложении она
 * стоит один раз, с суммой часов за все семестры и одной оценкой — последней выставленной.
 * Поэтому строки плана сворачиваются по дисциплине, а не переносятся как есть.
 *
 * **Собирать не из чего — это отказ, а не пустое приложение.** Пустое приложение,
 * напечатанное на бланке, — испорченный бланк строгой отчётности, а их считают поштучно.
 * Поэтому отсутствие плана или дисциплин в нём роняет сборку с названной причиной, а не
 * возвращает пустой список.
 *
 * **Ручной ввод при этом остаётся нетронутым.** Переводы, перезачёты и академические
 * разницы иначе некуда девать, и запрещать сохранение приложения, собранного руками,
 * нельзя. Отказывает **сборка**, а не выдача.
 */
class DiplomaSupplementAssembler
{
    /**
     * @return array{rows: array<int, array<string, mixed>>, problems: array<int, string>, ready: bool}
     *
     * @throws ValidationException когда собирать не из чего
     */
    public function assemble(Graduate $graduate): array
    {
        $student = $graduate->student;

        if ($student === null) {
            throw ValidationException::withMessages([
                'student_id' => 'Сборка приложения невозможна: выпускник не связан с карточкой студента, а оценки лежат у неё.',
            ]);
        }

        $curriculumId = $graduate->group?->curriculum_id;

        if ($curriculumId === null) {
            throw ValidationException::withMessages([
                'curriculum_id' => 'Сборка приложения невозможна: у группы выпускника не выбран учебный план. Из плана берутся дисциплины и часы — без него собирать не из чего.',
            ]);
        }

        $planRows = CurriculumSubject::query()
            ->with('subject')
            ->where('curriculum_id', $curriculumId)
            ->orderBy('semester')
            ->orderBy('sequence')
            ->get();

        if ($planRows->isEmpty()) {
            throw ValidationException::withMessages([
                'curriculum_id' => 'Сборка приложения невозможна: в учебном плане группы нет ни одной дисциплины.',
            ]);
        }

        $grades = SemesterGrade::query()
            ->where('student_id', $student->id)
            ->get()
            ->groupBy('subject_id');

        $rows = [];
        $problems = [];

        foreach ($planRows->groupBy('subject_id') as $subjectId => $subjectRows) {
            $last = $subjectRows->last();
            $mark = $this->latestMark($grades->get($subjectId));
            $name = $last->subject?->name ?: 'Дисциплина №'.$subjectId;

            if ($mark === null) {
                // Строка всё равно попадает в список: секретарь должен видеть, чего не
                // хватает, а не гадать, почему дисциплин меньше, чем в плане.
                $problems[] = 'Нет итоговой оценки: '.$name;
            }

            $rows[] = [
                'subject_id' => (int) $subjectId,
                'subject' => $name,
                'hours' => (int) $subjectRows->sum('total_hours'),
                'control_type' => $last->control_type,
                'semester' => (int) $last->semester,
                'value' => $mark?->value,
                'set_at' => $mark?->set_at?->toDateString(),
            ];
        }

        return [
            'rows' => $rows,
            'problems' => $problems,
            'ready' => $problems === [],
        ];
    }

    /**
     * Последняя выставленная оценка по дисциплине.
     *
     * «Последняя» — по учебному году и семестру, а не по времени записи: пересдачу могут
     * внести позже, чем оценку следующего семестра, и порядок записи тогда соврёт.
     *
     * @param Collection<int, SemesterGrade>|null $marks
     */
    private function latestMark(?Collection $marks): ?SemesterGrade
    {
        return $marks?->sortBy([
            ['academic_year', 'asc'],
            ['semester', 'asc'],
        ])->last();
    }
}
