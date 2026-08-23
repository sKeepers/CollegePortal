<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\JournalAttendance;
use App\Models\JournalGrade;
use App\Models\JournalLesson;
use App\Models\Student;
use App\Models\Subject;
use App\Services\JournalLessonAccess;
use App\Support\Csv\CsvExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Сводные отчёты журнала по группе.
 *
 * Право `journal.view` открывает раздел, но не выбор группы: до 16.08.2026
 * `group_id` брался из запроса как есть, и любой преподаватель мог построить
 * оценки и посещаемость **любой** группы колледжа. Теперь группу проверяет
 * `JournalLessonAccess::canReadGroup` — тем же кодом, которым журнал решает,
 * чьи занятия человек видит.
 */
class ReportController extends Controller
{
    public function __construct(private readonly JournalLessonAccess $access)
    {
    }

    public function attendanceByGroup(Request $request): JsonResponse
    {
        $validated = $this->validateAttendanceReportRequest($request);

        return response()->json([
            'data' => $this->buildAttendanceByGroupReport($validated),
        ]);
    }

    public function exportAttendanceByGroup(Request $request): StreamedResponse|JsonResponse
    {
        $validated = $this->validateAttendanceReportRequest($request);
        $report = $this->buildAttendanceByGroupReport($validated);

        // Заголовки по-русски: отчёт открывают в Excel и подшивают, а не разбирают
        // кодом. Машинные имена колонок читать здесь некому.
        return CsvExport::download('attendance-report.csv', [
            'Студент',
            'Группа',
            'Занятий всего',
            'Отмечено',
            'Присутствовал',
            'Отсутствовал',
            'Опоздал',
            'По уважительной',
            'Болел',
            'Дистанционно',
            'Без отметки',
        ], function (callable $row) use ($report): void {
            foreach ($report['students'] as $student) {
                $row([
                    $student['name'],
                    $report['group']['name'],
                    $report['summary']['total_lessons'],
                    $student['marked_total'],
                    $student['present'],
                    $student['absent'],
                    $student['late'],
                    $student['excused'],
                    $student['sick'],
                    $student['remote'],
                    $student['unmarked'],
                ]);
            }
        });
    }

    public function gradesByGroup(Request $request): JsonResponse
    {
        $validated = $this->validateGradeReportRequest($request);

        return response()->json([
            'data' => $this->buildGradesByGroupReport($validated),
        ]);
    }

    public function exportGradesByGroup(Request $request): StreamedResponse|JsonResponse
    {
        $validated = $this->validateGradeReportRequest($request);
        $report = $this->buildGradesByGroupReport($validated);

        return CsvExport::download('grades-report.csv', [
            'Студент',
            'Группа',
            'Дисциплина',
            'Оценки',
            'Числовых оценок',
            'Средний балл',
        ], function (callable $row) use ($report): void {
            foreach ($report['students'] as $student) {
                $row([
                    $student['name'],
                    $report['group']['name'],
                    $report['subject']['name'],
                    collect($student['grades'])->join(', '),
                    $student['numeric_grades_count'],
                    $student['average_grade'],
                ]);
            }
        });
    }

    private function validateAttendanceReportRequest(Request $request): array
    {
        $validated = Validator::make($request->all(), [
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ], [
            'group_id.required' => 'Выберите группу.',
            'group_id.exists' => 'Группа не найдена.',
            'date_to.after_or_equal' => 'Дата окончания должна быть не раньше даты начала.',
        ])->validate();

        $this->authorizeGroup($request, (int) $validated['group_id']);

        return $validated;
    }

    /**
     * Единственная точка, где решается, чью группу можно спросить.
     *
     * Проверка стоит в разборе запроса намеренно: через него проходят все
     * четыре маршрута — два отчёта и две выгрузки, — и новый отчёт не
     * проскочит мимо неё молча.
     */
    private function authorizeGroup(Request $request, int $groupId): void
    {
        abort_unless(
            $this->access->canReadGroup($request->user(), $groupId),
            403,
            'Отчёт строится по своим группам: где вы куратор или ведёте занятия.',
        );
    }

    private function validateGradeReportRequest(Request $request): array
    {
        $validated = Validator::make($request->all(), [
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ], [
            'group_id.required' => 'Выберите группу.',
            'subject_id.required' => 'Выберите дисциплину.',
            'group_id.exists' => 'Группа не найдена.',
            'subject_id.exists' => 'Дисциплина не найдена.',
            'date_to.after_or_equal' => 'Дата окончания должна быть не раньше даты начала.',
        ])->validate();

        $this->authorizeGroup($request, (int) $validated['group_id']);

        return $validated;
    }

    private function buildAttendanceByGroupReport(array $filters): array
    {
        $group = Group::findOrFail($filters['group_id']);
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        // Занятия и отметки берутся из журнала: там их ставит преподаватель.
        // Старая пара таблиц с июля наполнялась только демонстрационным
        // набором, и отчёт по ней показывал не работу, а набор.
        $lessonIds = JournalLesson::query()
            ->where('group_id', $group->id)
            ->when($dateFrom, fn ($query, string $date) => $query->whereDate('lesson_date', '>=', $date))
            ->when($dateTo, fn ($query, string $date) => $query->whereDate('lesson_date', '<=', $date))
            ->pluck('id');

        $totalLessons = $lessonIds->count();

        $attendance = JournalAttendance::query()
            ->whereIn('journal_lesson_id', $lessonIds)
            // Строки `roster` журнал заводит сам при открытии занятия, до того
            // как кого-либо отметили: считать их отметками значит показать
            // «присутствовал» там, где занятие ещё не вели.
            ->where('source', '!=', 'roster')
            ->get()
            ->groupBy('student_id');

        $students = Student::query()
            ->where('group_id', $group->id)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(function (Student $student) use ($attendance, $totalLessons): array {
                $studentAttendance = $attendance->get($student->id, collect());
                $counts = $studentAttendance->countBy('status');
                $markedTotal = $studentAttendance->count();

                return [
                    'id' => $student->id,
                    'name' => collect([$student->last_name, $student->first_name, $student->middle_name])->filter()->join(' '),
                    'present' => $counts->get('present', 0),
                    'absent' => $counts->get('absent', 0),
                    'late' => $counts->get('late', 0),
                    'excused' => $counts->get('excused', 0),
                    // Журнал знает два статуса, которых старая таблица не
                    // знала. Не показать их значило бы потерять отметки.
                    'sick' => $counts->get('sick', 0),
                    'remote' => $counts->get('remote', 0),
                    'marked_total' => $markedTotal,
                    'unmarked' => max(0, $totalLessons - $markedTotal),
                ];
            })
            ->values();

        return [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
            ],
            'period' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'summary' => [
                'total_lessons' => $totalLessons,
                'students_count' => $students->count(),
                'present' => $students->sum('present'),
                'absent' => $students->sum('absent'),
                'late' => $students->sum('late'),
                'excused' => $students->sum('excused'),
                'sick' => $students->sum('sick'),
                'remote' => $students->sum('remote'),
                'unmarked' => $students->sum('unmarked'),
            ],
            'students' => $students,
        ];
    }

    private function buildGradesByGroupReport(array $filters): array
    {
        $group = Group::findOrFail($filters['group_id']);
        $subject = Subject::findOrFail($filters['subject_id']);
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        $lessonIds = JournalLesson::query()
            ->where('group_id', $group->id)
            ->where('subject_id', $subject->id)
            ->when($dateFrom, fn ($query, string $date) => $query->whereDate('lesson_date', '>=', $date))
            ->when($dateTo, fn ($query, string $date) => $query->whereDate('lesson_date', '<=', $date))
            ->pluck('id');

        $grades = JournalGrade::query()
            ->whereIn('journal_lesson_id', $lessonIds)
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->get()
            ->groupBy('student_id');

        $students = Student::query()
            ->where('group_id', $group->id)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(function (Student $student) use ($grades): array {
                $studentGrades = $grades->get($student->id, collect());
                $gradeValues = $studentGrades->pluck('value')->values();
                $numericGrades = $gradeValues
                    ->filter(fn ($grade) => is_numeric($grade))
                    ->map(fn ($grade) => (float) $grade)
                    ->values();

                return [
                    'id' => $student->id,
                    'name' => collect([$student->last_name, $student->first_name, $student->middle_name])->filter()->join(' '),
                    'grades' => $gradeValues,
                    'grades_count' => $gradeValues->count(),
                    'numeric_grades_count' => $numericGrades->count(),
                    'average_grade' => $numericGrades->isEmpty()
                        ? null
                        : round($numericGrades->avg(), 2),
                ];
            })
            ->values();

        $allNumericGrades = $students
            ->flatMap(fn (array $student) => collect($student['grades'])->filter(fn ($grade) => is_numeric($grade))->map(fn ($grade) => (float) $grade));

        return [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
            ],
            'subject' => [
                'id' => $subject->id,
                'name' => $subject->name,
            ],
            'period' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'summary' => [
                'students_count' => $students->count(),
                'lessons_count' => $lessonIds->count(),
                'grades_count' => $students->sum('grades_count'),
                'numeric_grades_count' => $allNumericGrades->count(),
                'average_grade' => $allNumericGrades->isEmpty()
                    ? null
                    : round($allNumericGrades->avg(), 2),
            ],
            'students' => $students,
        ];
    }
}
