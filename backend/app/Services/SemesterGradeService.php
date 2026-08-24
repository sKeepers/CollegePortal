<?php

namespace App\Services;

use App\Models\CurriculumSubject;
use App\Models\Group;
use App\Models\JournalLesson;
use App\Models\SemesterGrade;
use App\Models\Student;
use App\Models\TeachingLoadItem;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Ведомость итоговых оценок: кто их видит, кто ставит и как они пишутся.
 *
 * Ведомость строится **от списка группы**, а не от списка оценок: преподавателю нужен
 * весь состав, включая тех, кому он ещё ничего не поставил, — иначе он не увидит, кого
 * пропустил, а именно это и есть работа в конце семестра.
 */
class SemesterGradeService
{
    public function __construct(private readonly CuratorScopeService $curatorScope)
    {
    }

    /**
     * Студенты группы и их итоговые оценки по дисциплине за семестр.
     *
     * @return array{students: array<int, array<string, mixed>>, control_type: ?string, curriculum_subject_id: ?int}
     */
    public function sheet(int $groupId, int $subjectId, string $academicYear, int $semester): array
    {
        $students = Student::query()
            ->where('group_id', $groupId)
            ->orderBy('last_name')->orderBy('first_name')
            ->get(['id', 'last_name', 'first_name', 'middle_name', 'group_id']);

        $grades = SemesterGrade::query()
            ->whereIn('student_id', $students->pluck('id'))
            ->where('subject_id', $subjectId)
            ->where('academic_year', $academicYear)
            ->where('semester', $semester)
            ->get()
            ->keyBy('student_id');

        $plan = $this->curriculumSubject($groupId, $subjectId, $semester);

        return [
            'control_type' => $plan?->control_type,
            'curriculum_subject_id' => $plan?->id,
            'students' => $students->map(function (Student $student) use ($grades): array {
                $grade = $grades->get($student->id);

                return [
                    'student_id' => $student->id,
                    'name' => trim(implode(' ', array_filter([
                        $student->last_name, $student->first_name, $student->middle_name,
                    ]))),
                    'value' => $grade?->value,
                    'score' => $grade?->score,
                    'comment' => $grade?->comment,
                    'set_at' => $grade?->set_at?->toIso8601String(),
                ];
            })->all(),
        ];
    }

    /**
     * Может ли человек ставить итоговую оценку в этой группе по этой дисциплине.
     *
     * Право `journal.semester_grades` проверяет маршрут; здесь решается второе — **свою
     * ли дисциплину** человек закрывает. Учебная часть закрывает любую (`journal.view_all`),
     * преподаватель — ту, которую вёл. Ведение ищется и в нагрузке, и в журнале: нагрузка
     * может быть ещё не расписана, а занятия уже прошли, и наоборот.
     *
     * Куратор сюда не попадает намеренно: он отвечает за группу, а не за дисциплину, и
     * итоговую оценку по чужому предмету ставить не может.
     */
    public function canGrade(User $user, int $groupId, int $subjectId): bool
    {
        if ($user->hasRole('admin') || $user->hasPermission('journal.view_all')) {
            return true;
        }

        $teacherIds = $this->curatorScope->teacherIds($user);

        if ($teacherIds->isEmpty()) {
            return false;
        }

        return TeachingLoadItem::query()
            ->where('group_id', $groupId)
            ->where('subject_id', $subjectId)
            ->whereIn('teacher_id', $teacherIds->all())
            ->exists()
            || JournalLesson::query()
                ->where('group_id', $groupId)
                ->where('subject_id', $subjectId)
                ->whereIn('teacher_id', $teacherIds->all())
                ->exists();
    }

    /**
     * Записать ведомость.
     *
     * **Пустое значение снимает оценку**, а не пишет пустую строку: преподаватель,
     * поставивший не тому студенту, обязан иметь способ это убрать, и «стереть» здесь
     * ровно то же действие, что и в журнале.
     *
     * Пишется через `firstOrNew` + `save`, а не `updateOrCreate`: последний внутри
     * транзакции открывает точку сохранения на каждую строку, а таблица блокировок одна
     * на весь сервер — на этом уже валился демонстрационный набор. Ведомость курса это
     * тридцать строк, но ведомость специальности за семестр — уже тысячи.
     *
     * @param array<int, array{student_id: int, value?: ?string, score?: ?int, comment?: ?string}> $rows
     * @return array{saved: int, removed: int, skipped: int}
     */
    public function save(int $groupId, int $subjectId, string $academicYear, int $semester, array $rows, User $user): array
    {
        $studentIds = Student::query()->where('group_id', $groupId)->pluck('id')->all();
        $plan = $this->curriculumSubject($groupId, $subjectId, $semester);
        $teacherId = $this->curatorScope->teacherIds($user)->first();
        $now = now();
        $result = ['saved' => 0, 'removed' => 0, 'skipped' => 0];

        foreach ($rows as $row) {
            $studentId = (int) ($row['student_id'] ?? 0);

            // Студент не из этой группы — молча мимо: ведомость правит состав группы, а
            // не произвольный список, и чужая строка здесь значит ошибку вызова.
            if (! in_array($studentId, $studentIds, true)) {
                $result['skipped']++;

                continue;
            }

            $value = trim((string) ($row['value'] ?? ''));
            $existing = SemesterGrade::query()
                ->where('student_id', $studentId)
                ->where('subject_id', $subjectId)
                ->where('academic_year', $academicYear)
                ->where('semester', $semester)
                ->first();

            if ($value === '') {
                if ($existing !== null) {
                    $existing->delete();
                    $result['removed']++;
                }

                continue;
            }

            $grade = $existing ?? new SemesterGrade([
                'student_id' => $studentId,
                'subject_id' => $subjectId,
                'academic_year' => $academicYear,
                'semester' => $semester,
            ]);

            $grade->fill([
                'group_id' => $groupId,
                'curriculum_subject_id' => $plan?->id,
                'control_type' => $plan?->control_type,
                'value' => $value,
                'score' => $row['score'] ?? null,
                'comment' => $row['comment'] ?? null,
                'teacher_id' => $teacherId,
                'set_by' => $user->id,
                'set_at' => $now,
                'source' => 'manual',
            ])->save();

            $result['saved']++;
        }

        return $result;
    }

    /**
     * Строка учебного плана для этой дисциплины и семестра.
     *
     * Может не найтись, и это рабочий случай: планы приходят позже оценок. Тогда часы и
     * форма контроля останутся пустыми, а оценка запишется — ждать плана значило бы не
     * собрать первый семестр вовсе.
     */
    private function curriculumSubject(int $groupId, int $subjectId, int $semester): ?CurriculumSubject
    {
        $curriculumId = Group::query()->whereKey($groupId)->value('curriculum_id');

        if ($curriculumId === null) {
            return null;
        }

        return CurriculumSubject::query()
            ->where('curriculum_id', $curriculumId)
            ->where('subject_id', $subjectId)
            ->where('semester', $semester)
            ->first();
    }

    /** @return Collection<int, int> */
    public function teacherIdsOf(User $user): Collection
    {
        return $this->curatorScope->teacherIds($user);
    }
}
