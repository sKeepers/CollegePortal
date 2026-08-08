<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Grade;
use App\Models\Group;
use App\Models\ScheduleLesson;
use App\Models\Student;
use App\Models\Subject;
use App\Support\Csv\CsvExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
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

        return CsvExport::download('attendance-report.csv', [
            'student',
            'group',
            'total_lessons',
            'marked_total',
            'present',
            'absent',
            'late',
            'excused',
            'unmarked',
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
            'student',
            'group',
            'subject',
            'grades',
            'numeric_grades_count',
            'average_grade',
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
        return Validator::make($request->all(), [
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ], [
            'group_id.required' => 'Выберите группу.',
            'group_id.exists' => 'Группа не найдена.',
            'date_to.after_or_equal' => 'Дата окончания должна быть не раньше даты начала.',
        ])->validate();
    }

    private function validateGradeReportRequest(Request $request): array
    {
        return Validator::make($request->all(), [
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
    }

    private function buildAttendanceByGroupReport(array $filters): array
    {
        $group = Group::findOrFail($filters['group_id']);
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        $lessonIds = ScheduleLesson::query()
            ->where('group_id', $group->id)
            ->when($dateFrom, fn ($query, string $date) => $query->whereDate('lesson_date', '>=', $date))
            ->when($dateTo, fn ($query, string $date) => $query->whereDate('lesson_date', '<=', $date))
            ->pluck('id');

        $totalLessons = $lessonIds->count();

        $attendance = Attendance::query()
            ->whereIn('schedule_lesson_id', $lessonIds)
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

        $lessonIds = ScheduleLesson::query()
            ->where('group_id', $group->id)
            ->where('subject_id', $subject->id)
            ->when($dateFrom, fn ($query, string $date) => $query->whereDate('lesson_date', '>=', $date))
            ->when($dateTo, fn ($query, string $date) => $query->whereDate('lesson_date', '<=', $date))
            ->pluck('id');

        $grades = Grade::query()
            ->whereIn('schedule_lesson_id', $lessonIds)
            ->get()
            ->groupBy('student_id');

        $students = Student::query()
            ->where('group_id', $group->id)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(function (Student $student) use ($grades): array {
                $studentGrades = $grades->get($student->id, collect());
                $gradeValues = $studentGrades->pluck('grade')->values();
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
