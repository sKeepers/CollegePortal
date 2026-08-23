<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\JournalLessonFileResource;
use App\Http\Resources\JournalLessonResource;
use App\Models\JournalLesson;
use App\Models\JournalLessonFile;
use App\Models\JournalEditRequest;
use App\Models\JournalAttendance;
use App\Models\JournalGrade;
use App\Models\AuditLog;
use App\Models\ScheduleEntry;
use App\Models\ScheduleLesson;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\CuratorScopeService;
use App\Services\JournalLessonAccess;
use App\Services\JournalService;
use App\Support\Csv\CsvExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JournalLessonController extends Controller
{
    /** Отметка человеческими словами: выгрузку открывают в Excel, а не разбирают кодом. */
    private const ATTENDANCE_WORDS = [
        'present' => 'Присутствовал',
        'absent' => 'Отсутствовал',
        'late' => 'Опоздал',
        'excused' => 'По уважительной',
        'sick' => 'Болел',
        'remote' => 'Дистанционно',
    ];

    public function __construct(
        private readonly JournalService $journalService,
        private readonly CuratorScopeService $curatorScope,
        private readonly JournalLessonAccess $access,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = JournalLesson::query()
            ->with(['group', 'subject', 'teacher', 'lessonType', 'scheduleEntry.classroom', 'attendance.student', 'grades', 'files', 'signedBy', 'reopenedBy'])
            ->when($request->integer('group_id'), fn (Builder $q, int $id) => $q->where('group_id', $id))
            ->when($request->integer('subject_id'), fn (Builder $q, int $id) => $q->where('subject_id', $id))
            ->when($request->integer('teacher_id'), fn (Builder $q, int $id) => $q->where('teacher_id', $id))
            ->when($request->string('status')->toString(), fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($request->date('date'), fn (Builder $q, $date) => $q->whereDate('lesson_date', $date));

        $this->applyDateRange($query, $request);
        $this->applyMode($query, $request->string('mode')->toString());
        $this->applyScope($query, $request->user());

        return JournalLessonResource::collection($query->orderBy('lesson_date')->orderBy('starts_at')->paginate($request->integer('per_page') ?: 20));
    }

    public function show(Request $request, JournalLesson $lesson): JournalLessonResource
    {
        $this->authorizeLesson($request->user(), $lesson, false, curatorMayRead: true);
        $loaded = $this->journalService->loadLesson($lesson);
        $this->filterStudentPayload($request->user(), $loaded);

        return new JournalLessonResource($loaded);
    }

    public function openFromSchedule(Request $request, ScheduleEntry $scheduleEntry): JournalLessonResource
    {
        $this->authorizeScheduleEntry($request->user(), $scheduleEntry, true);
        $lesson = $this->journalService->openFromSchedule($scheduleEntry, $request->user());

        return new JournalLessonResource($lesson);
    }

    public function openFromLegacySchedule(Request $request, ScheduleLesson $scheduleLesson): JournalLessonResource
    {
        $this->authorizeLegacyScheduleLesson($request->user(), $scheduleLesson, true);
        $lesson = $this->journalService->openFromLegacySchedule($scheduleLesson, $request->user());

        return new JournalLessonResource($lesson);
    }

    public function update(Request $request, JournalLesson $lesson): JournalLessonResource
    {
        $this->authorizeLesson($request->user(), $lesson, true);
        $data = $request->validate([
            'topic' => ['nullable', 'string', 'max:2000'],
            'homework' => ['nullable', 'string', 'max:2000'],
            'teacher_comment' => ['nullable', 'string', 'max:2000'],
            'homework_due_at' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['draft', 'in_progress', 'completed', 'signed', 'reopened', 'cancelled', 'planned', 'opened'])],
        ]);

        return new JournalLessonResource($this->journalService->updateLesson($lesson, $data, $request->user()));
    }

    public function complete(Request $request, JournalLesson $lesson): JournalLessonResource
    {
        abort_unless($request->user()->hasPermission('journal.complete'), 403);
        $this->authorizeLesson($request->user(), $lesson, true);

        return new JournalLessonResource($this->journalService->complete($lesson, $request->user()));
    }

    public function sign(Request $request, JournalLesson $lesson): JournalLessonResource
    {
        abort_unless($request->user()->hasPermission('journal.sign'), 403);
        $this->authorizeLesson($request->user(), $lesson, true);

        return new JournalLessonResource($this->journalService->sign($lesson, $request->user()));
    }

    public function reopen(Request $request, JournalLesson $lesson): JournalLessonResource
    {
        abort_unless($request->user()->hasPermission('journal.reopen'), 403);
        $this->authorizeLesson($request->user(), $lesson, true);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return new JournalLessonResource($this->journalService->reopen($lesson, $request->user(), $data['reason']));
    }

    public function requestEdit(Request $request, JournalLesson $lesson): JournalLessonResource
    {
        $this->authorizeLesson($request->user(), $lesson, false);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return new JournalLessonResource($this->journalService->requestEdit($lesson, $request->user(), $data['reason']));
    }

    public function reviewEditRequest(Request $request, JournalEditRequest $journalEditRequest): JournalLessonResource
    {
        abort_unless($request->user()->hasPermission('journal.reopen'), 403);
        $data = $request->validate([
            'approved' => ['required', 'boolean'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        return new JournalLessonResource($this->journalService->reviewEditRequest($journalEditRequest, $request->user(), $data['approved'], $data['comment'] ?? null));
    }

    public function pendingEditRequests(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('journal.reopen'), 403);

        $requests = JournalEditRequest::query()
            ->where('status', JournalEditRequest::STATUS_PENDING)
            ->with(['journalLesson.group', 'journalLesson.subject', 'journalLesson.teacher', 'requestedBy'])
            ->latest()
            ->get()
            ->map(fn (JournalEditRequest $editRequest) => [
                'id' => $editRequest->id,
                'journal_lesson_id' => $editRequest->journal_lesson_id,
                'reason' => $editRequest->reason,
                'created_at' => $editRequest->created_at?->toISOString(),
                'requested_by_name' => $editRequest->requestedBy?->name,
                'lesson' => [
                    'subject' => $editRequest->journalLesson?->subject?->name,
                    'group' => $editRequest->journalLesson?->group?->name,
                    'teacher' => trim(implode(' ', array_filter([
                        $editRequest->journalLesson?->teacher?->last_name,
                        $editRequest->journalLesson?->teacher?->first_name,
                        $editRequest->journalLesson?->teacher?->middle_name,
                    ]))),
                    'lesson_date' => $editRequest->journalLesson?->lesson_date?->toDateString(),
                ],
            ]);

        return response()->json(['data' => $requests]);
    }

    public function editRequestHistory(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('journal.reopen'), 403);
        $data = $request->validate([
            'status' => ['nullable', Rule::in([JournalEditRequest::STATUS_PENDING, JournalEditRequest::STATUS_APPROVED, JournalEditRequest::STATUS_REJECTED])],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'teacher_id' => ['nullable', 'integer', 'exists:teachers,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $requests = JournalEditRequest::query()
            ->with(['journalLesson.group', 'journalLesson.subject', 'journalLesson.teacher', 'requestedBy', 'reviewedBy'])
            ->when($data['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($data['group_id'] ?? null, fn (Builder $query, int $id) => $query->whereHas('journalLesson', fn (Builder $lesson) => $lesson->where('group_id', $id)))
            ->when($data['subject_id'] ?? null, fn (Builder $query, int $id) => $query->whereHas('journalLesson', fn (Builder $lesson) => $lesson->where('subject_id', $id)))
            ->when($data['teacher_id'] ?? null, fn (Builder $query, int $id) => $query->whereHas('journalLesson', fn (Builder $lesson) => $lesson->where('teacher_id', $id)))
            ->when($data['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereHas('journalLesson', fn (Builder $lesson) => $lesson->whereDate('lesson_date', '>=', $date)))
            ->when($data['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereHas('journalLesson', fn (Builder $lesson) => $lesson->whereDate('lesson_date', '<=', $date)))
            ->latest()
            ->paginate($data['per_page'] ?? 50);

        $lessonIds = $requests->getCollection()->pluck('journal_lesson_id')->unique()->values();
        $attendance = JournalAttendance::query()->whereIn('journal_lesson_id', $lessonIds)->get(['id', 'journal_lesson_id', 'student_id']);
        $grades = JournalGrade::query()->whereIn('journal_lesson_id', $lessonIds)->get(['id', 'journal_lesson_id', 'student_id']);
        $attendanceById = $attendance->keyBy('id');
        $gradesById = $grades->keyBy('id');
        $studentNames = Student::query()->whereIn('id', $attendance->pluck('student_id')->merge($grades->pluck('student_id'))->unique())
            ->get(['id', 'last_name', 'first_name', 'middle_name'])
            ->mapWithKeys(fn (Student $student) => [$student->id => trim(implode(' ', array_filter([$student->last_name, $student->first_name, $student->middle_name])))]);

        $auditLogs = AuditLog::query()
            ->with('user')
            ->where('module', 'journal')
            ->whereIn('action', ['edit_requested', 'edit_request_approved', 'edit_request_rejected', 'reopen', 'update_lesson', 'attendance_update', 'grade_update'])
            ->where(function (Builder $query) use ($lessonIds, $attendanceById, $gradesById): void {
                $query->where(fn (Builder $audit) => $audit->where('entity_type', 'JournalLesson')->whereIn('entity_id', $lessonIds))
                    ->orWhere(fn (Builder $audit) => $audit->where('entity_type', 'JournalAttendance')->whereIn('entity_id', $attendanceById->keys()))
                    ->orWhere(fn (Builder $audit) => $audit->where('entity_type', 'JournalGrade')->whereIn('entity_id', $gradesById->keys()));
            })
            ->oldest('created_at')
            ->get();

        $requests->getCollection()->transform(function (JournalEditRequest $editRequest) use ($auditLogs, $attendanceById, $gradesById, $studentNames): array {
            $lesson = $editRequest->journalLesson;
            $changes = $auditLogs->filter(function (AuditLog $audit) use ($editRequest, $attendanceById, $gradesById): bool {
                if ($audit->created_at->lt($editRequest->created_at)) {
                    return false;
                }

                if ($audit->entity_type === 'JournalLesson') {
                    return (int) $audit->entity_id === (int) $editRequest->journal_lesson_id;
                }

                $record = $audit->entity_type === 'JournalAttendance' ? $attendanceById->get($audit->entity_id) : $gradesById->get($audit->entity_id);

                return $record && (int) $record->journal_lesson_id === (int) $editRequest->journal_lesson_id;
            })->map(function (AuditLog $audit) use ($attendanceById, $gradesById, $studentNames): array {
                $record = $audit->entity_type === 'JournalAttendance' ? $attendanceById->get($audit->entity_id) : $gradesById->get($audit->entity_id);

                return [
                    'id' => $audit->id,
                    'action' => $audit->action,
                    'created_at' => $audit->created_at?->toISOString(),
                    'user_name' => $audit->user?->name,
                    'student_name' => $record ? $studentNames->get($record->student_id) : null,
                    'old_values' => $audit->old_values,
                    'new_values' => $audit->new_values,
                ];
            })->values();

            return [
                'id' => $editRequest->id,
                'status' => $editRequest->status,
                'reason' => $editRequest->reason,
                'review_comment' => $editRequest->review_comment,
                'created_at' => $editRequest->created_at?->toISOString(),
                'reviewed_at' => $editRequest->reviewed_at?->toISOString(),
                'requested_by_name' => $editRequest->requestedBy?->name,
                'reviewed_by_name' => $editRequest->reviewedBy?->name,
                'lesson' => [
                    'id' => $lesson?->id,
                    'subject' => $lesson?->subject?->name,
                    'group' => $lesson?->group?->name,
                    'teacher' => trim(implode(' ', array_filter([$lesson?->teacher?->last_name, $lesson?->teacher?->first_name, $lesson?->teacher?->middle_name]))),
                    'lesson_date' => $lesson?->lesson_date?->toDateString(),
                ],
                'changes' => $changes,
            ];
        });

        return response()->json($requests);
    }

    public function attendance(Request $request, JournalLesson $lesson): JournalLessonResource
    {
        abort_unless($request->user()->hasPermission('journal.attendance'), 403);
        $this->authorizeLesson($request->user(), $lesson, true);
        $data = $request->validate([
            'attendance' => ['required', 'array'],
            'attendance.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'attendance.*.status' => ['required', Rule::in(['present', 'absent', 'late', 'excused', 'sick', 'remote'])],
            'attendance.*.minutes_late' => ['nullable', 'integer', 'min:0', 'max:600'],
            'attendance.*.comment' => ['nullable', 'string', 'max:1000'],
        ]);

        return new JournalLessonResource($this->journalService->saveAttendance($lesson, $data['attendance'], $request->user()));
    }

    public function grades(Request $request, JournalLesson $lesson): JournalLessonResource
    {
        abort_unless($request->user()->hasPermission('journal.grades'), 403);
        $this->authorizeLesson($request->user(), $lesson, true);
        $data = $request->validate([
            'grades' => ['required', 'array'],
            'grades.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'grades.*.grade_type_id' => ['nullable', 'integer', 'exists:reference_items,id'],
            'grades.*.value' => ['nullable', 'string', 'max:20'],
            'grades.*.weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'grades.*.comment' => ['nullable', 'string', 'max:1000'],
        ]);

        return new JournalLessonResource($this->journalService->saveGrades($lesson, $data['grades'], $request->user()));
    }

    public function attendanceSuggestion(Request $request, JournalLesson $lesson): JsonResponse
    {
        $this->authorizeLesson($request->user(), $lesson, false);

        return response()->json(['data' => $this->journalService->attendanceSuggestion($lesson)]);
    }

    public function applyAttendanceSuggestion(Request $request, JournalLesson $lesson): JournalLessonResource
    {
        abort_unless($request->user()->hasPermission('journal.attendance'), 403);
        $this->authorizeLesson($request->user(), $lesson, true);

        return new JournalLessonResource($this->journalService->applyAttendanceSuggestion($lesson, $request->user()));
    }

    public function storeFile(Request $request, JournalLesson $lesson): JournalLessonFileResource
    {
        abort_unless($request->user()->hasPermission('journal.files'), 403);
        $this->authorizeLesson($request->user(), $lesson, true);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,docx,xlsx,pptx,jpg,jpeg,png'],
        ]);

        return new JournalLessonFileResource($this->journalService->storeFile($lesson, $data['file'], $request->user()));
    }

    public function downloadFile(Request $request, JournalLesson $lesson, JournalLessonFile $file): StreamedResponse
    {
        $this->authorizeLesson($request->user(), $lesson, false, curatorMayRead: true);
        abort_unless((int) $file->journal_lesson_id === (int) $lesson->id, 404);

        return Storage::disk('local')->download($file->path, $file->original_name);
    }

    public function destroyFile(Request $request, JournalLesson $lesson, JournalLessonFile $file): JsonResponse
    {
        abort_unless($request->user()->hasPermission('journal.files'), 403);
        $this->authorizeLesson($request->user(), $lesson, true);
        abort_unless((int) $file->journal_lesson_id === (int) $lesson->id, 404);
        $this->journalService->deleteFile($file, $request->user());

        return response()->json(['message' => 'Файл удален.']);
    }

    public function exportLesson(Request $request, JournalLesson $lesson): StreamedResponse
    {
        abort_unless($request->user()->hasPermission('journal.export'), 403);
        $this->authorizeLesson($request->user(), $lesson, false, curatorMayRead: true);
        $lesson = $this->journalService->loadLesson($lesson);

        return CsvExport::download("journal-lesson-{$lesson->id}.csv", ['student', 'attendance', 'minutes_late', 'grade', 'comment'], function (callable $row) use ($lesson): void {
            foreach ($lesson->attendance as $attendance) {
                $grade = $lesson->grades->firstWhere('student_id', $attendance->student_id);
                $student = $attendance->student;
                $row([
                    trim("{$student->last_name} {$student->first_name} {$student->middle_name}"),
                    $attendance->status,
                    $attendance->minutes_late,
                    $grade?->value,
                    $attendance->comment,
                ]);
            }
        });
    }


    public function exportGroup(Request $request): StreamedResponse
    {
        abort_unless($request->user()->hasPermission('journal.export'), 403);
        $data = $request->validate([
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $query = JournalLesson::query()
            ->with(['group', 'subject', 'teacher', 'attendance.student', 'grades'])
            ->where('group_id', $data['group_id']);
        $this->applyDateRange($query, $request);
        $this->applyScope($query, $request->user());

        return $this->streamLessonsCsv($query->orderBy('lesson_date')->orderBy('starts_at')->get(), 'journal-group.csv');
    }

    public function exportTeacher(Request $request): StreamedResponse
    {
        abort_unless($request->user()->hasPermission('journal.export'), 403);
        $data = $request->validate([
            'teacher_id' => ['nullable', 'integer', 'exists:teachers,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $query = JournalLesson::query()
            ->with(['group', 'subject', 'teacher', 'attendance.student', 'grades'])
            ->when($data['teacher_id'] ?? null, fn (Builder $q, int $id) => $q->where('teacher_id', $id));
        $this->applyDateRange($query, $request);
        $this->applyScope($query, $request->user());

        return $this->streamLessonsCsv($query->orderBy('lesson_date')->orderBy('starts_at')->get(), 'journal-teacher.csv');
    }

    /**
     * Печатная форма журнала: страница бумажного журнала как она есть — студенты
     * по строкам, занятия по столбцам, в клетке отметка и оценка.
     *
     * Такой формы не было вовсе: выгрузки отдавали «длинный» список, где на
     * каждого студента каждого занятия приходится своя строка. Читать его на
     * бумаге нельзя, а учебная часть подшивает журнал именно страницами.
     *
     * Пустая клетка значит «был»: так устроен бумажный журнал, и переучивать
     * человека здесь незачем.
     */
    private const GRID_MARKS = [
        'present' => '',
        'absent' => 'н',
        'late' => 'оп',
        'excused' => 'ув',
        'sick' => 'б',
        'remote' => 'д',
    ];

    public function grid(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->buildGrid($request)]);
    }

    public function exportGrid(Request $request): StreamedResponse
    {
        abort_unless($request->user()->hasPermission('journal.export'), 403, 'Права на выгрузку журнала нет.');
        $grid = $this->buildGrid($request);

        $headers = array_merge(
            ['Студент'],
            array_column($grid['lessons'], 'column'),
            ['Пропусков', 'Опозданий', 'Средний балл'],
        );

        return CsvExport::download('journal-grid.csv', $headers, function (callable $row) use ($grid): void {
            foreach ($grid['students'] as $student) {
                $cells = [];
                foreach ($grid['lessons'] as $lesson) {
                    $cells[] = $student['cells'][$lesson['id']] ?? '';
                }
                $row(array_merge(
                    [$student['full_name']],
                    $cells,
                    [$student['absences'], $student['lates'], $student['average']],
                ));
            }
        });
    }

    /** @return array<string, mixed> */
    private function buildGrid(Request $request): array
    {
        $data = $request->validate([
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $user = $request->user();
        abort_unless(
            $this->access->canReadGroup($user, (int) $data['group_id']),
            403,
            'Журнал этой группы вам не показывают: вы её не курируете и в ней не преподаёте.',
        );

        $lessons = JournalLesson::query()
            ->with(['subject', 'teacher', 'attendance', 'grades'])
            ->where('group_id', $data['group_id'])
            ->when($data['subject_id'] ?? null, fn (Builder $q, int $id) => $q->where('subject_id', $id))
            ->when($data['date_from'] ?? null, fn (Builder $q, string $d) => $q->whereDate('lesson_date', '>=', $d))
            ->when($data['date_to'] ?? null, fn (Builder $q, string $d) => $q->whereDate('lesson_date', '<=', $d))
            ->orderBy('lesson_date')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        // Отчисленные и переведённые в столбцах не нужны, а вот в архив ушедшие
        // в середине периода — нужны: их пропуски за этот период настоящие.
        $students = Student::query()
            ->where('group_id', $data['group_id'])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $seenDates = [];
        $columns = [];
        foreach ($lessons as $lesson) {
            $date = $lesson->lesson_date?->format('d.m') ?? '';
            $seenDates[$date] = ($seenDates[$date] ?? 0) + 1;
            $columns[] = [
                'id' => $lesson->id,
                'date' => $lesson->lesson_date?->toDateString(),
                // Две пары в один день дают два одинаковых заголовка, и на бумаге
                // их не различить. Второй и следующие получают номер.
                'column' => $seenDates[$date] > 1 ? $date.' ('.$seenDates[$date].')' : $date,
                'starts_at' => $lesson->starts_at?->format('H:i'),
                'subject' => $lesson->subject?->name,
                'teacher' => $this->personName($lesson->teacher),
                'topic' => $lesson->topic,
            ];
        }

        $rows = [];
        foreach ($students as $student) {
            $cells = [];
            $absences = 0;
            $lates = 0;
            $marks = [];

            foreach ($lessons as $lesson) {
                $attendance = $lesson->attendance->firstWhere('student_id', $student->id);
                $grade = $lesson->grades->firstWhere('student_id', $student->id);
                $status = $attendance?->status;

                if ($status === 'absent') {
                    $absences++;
                } elseif ($status === 'late') {
                    $lates++;
                }

                if ($grade && is_numeric($grade->value)) {
                    $marks[] = (float) $grade->value;
                }

                $parts = array_filter([
                    self::GRID_MARKS[$status] ?? '',
                    (string) ($grade?->value ?? ''),
                ], fn (string $part): bool => $part !== '');

                $cells[$lesson->id] = implode(' ', $parts);
            }

            $rows[] = [
                'student_id' => $student->id,
                'full_name' => $this->personName($student),
                'cells' => $cells,
                'absences' => $absences,
                'lates' => $lates,
                'average' => $marks === [] ? '' : round(array_sum($marks) / count($marks), 2),
            ];
        }

        return [
            'group' => ['id' => (int) $data['group_id'], 'name' => $students->first()?->group?->name],
            'date_from' => $data['date_from'] ?? $lessons->first()?->lesson_date?->toDateString(),
            'date_to' => $data['date_to'] ?? $lessons->last()?->lesson_date?->toDateString(),
            'lessons' => $columns,
            'students' => $rows,
            'legend' => ['н' => 'не был', 'оп' => 'опоздал', 'ув' => 'по уважительной', 'б' => 'болел', 'д' => 'дистанционно', '' => 'был'],
        ];
    }

    private function personName(?object $person): string
    {
        if ($person === null) {
            return '';
        }

        return trim("{$person->last_name} {$person->first_name} {$person->middle_name}");
    }

    private function streamLessonsCsv($lessons, string $filename): StreamedResponse
    {
        return CsvExport::download($filename, ['Дата', 'Начало', 'Группа', 'Дисциплина', 'Преподаватель', 'Студент', 'Отметка', 'Опоздание, мин', 'Оценка', 'Комментарий'], function (callable $row) use ($lessons): void {
            foreach ($lessons as $lesson) {
                foreach ($lesson->attendance as $attendance) {
                    $grade = $lesson->grades->firstWhere('student_id', $attendance->student_id);
                    $student = $attendance->student;
                    $row([
                        $lesson->lesson_date,
                        $lesson->starts_at,
                        $lesson->group?->name,
                        $lesson->subject?->name,
                        trim("{$lesson->teacher?->last_name} {$lesson->teacher?->first_name} {$lesson->teacher?->middle_name}"),
                        $student ? trim("{$student->last_name} {$student->first_name} {$student->middle_name}") : '',
                        self::ATTENDANCE_WORDS[$attendance->status] ?? $attendance->status,
                        $attendance->minutes_late,
                        $grade?->value,
                        $attendance->comment,
                    ]);
                }
            }
        });
    }

    private function applyDateRange(Builder $query, Request $request): void
    {
        if ($request->date('date_from')) {
            $query->whereDate('lesson_date', '>=', $request->date('date_from'));
        }
        if ($request->date('date_to')) {
            $query->whereDate('lesson_date', '<=', $request->date('date_to'));
        }
    }

    private function applyMode(Builder $query, string $mode): void
    {
        match ($mode) {
            'mine' => null,
            'today' => $query->whereDate('lesson_date', today()),
            'tomorrow' => $query->whereDate('lesson_date', today()->addDay()),
            'week' => $query->whereBetween('lesson_date', [today()->startOfWeek(), today()->endOfWeek()]),
            'completed' => $query->whereIn('status', ['completed', 'signed']),
            'not_filled', 'needs_fill' => $query->where(function (Builder $q): void {
                $q->whereIn('status', ['draft', 'in_progress', 'reopened', 'planned', 'opened'])
                    ->orWhereNull('topic');
            }),
            'signed' => $query->where('status', 'signed'),
            'control' => $query->whereIn('status', ['draft', 'in_progress', 'completed', 'signed', 'reopened', 'planned', 'opened']),
            default => null,
        };
    }

    /**
     * Чьи занятия человек видит в списках и выгрузках.
     *
     * Преподаватель — свои. Куратор — ещё и занятия своей группы, которые ведут
     * другие: он тот же преподаватель, но по своей группе только смотрит.
     * Правку это не открывает ни на строку — её пропускает `authorizeLesson`, и
     * только на собственное занятие.
     *
     * Карточек преподавателя у учётной записи может быть несколько (на стенде у
     * `teacher@local` их две), поэтому отбор идёт по всем, а не по первой:
     * иначе половина собственных занятий пропадает из журнала молча.
     */
    private function applyScope(Builder $query, User $user): void
    {
        if ($user->hasPermission('journal.view_all')) {
            return;
        }

        $teacherIds = $this->curatorScope->teacherIds($user);

        if ($teacherIds->isNotEmpty()) {
            $curatedGroupIds = $this->curatorScope->curatedGroupIds($user);

            $query->where(function (Builder $scoped) use ($teacherIds, $curatedGroupIds): void {
                $scoped->whereIn('teacher_id', $teacherIds->all());

                if ($curatedGroupIds->isNotEmpty()) {
                    $scoped->orWhereIn('group_id', $curatedGroupIds->all());
                }
            });

            return;
        }

        if ($student = Student::query()->where('user_id', $user->id)->first()) {
            $query->where('group_id', $student->group_id);
            return;
        }

        if (! $user->hasRole('admin')) {
            $query->whereRaw('1 = 0');
        }
    }

    private function authorizeScheduleEntry(User $user, ScheduleEntry $entry, bool $write): void
    {
        if ($write && ! $user->hasPermission('journal.edit')) {
            abort(403);
        }
        if ($user->hasRole('admin') || $user->hasPermission('journal.view_all')) {
            return;
        }
        $teacherId = Teacher::query()->where('user_id', $user->id)->value('id');
        if ($teacherId && (int) $entry->teacher_id === (int) $teacherId) {
            return;
        }
        abort(403);
    }

    private function authorizeLegacyScheduleLesson(User $user, ScheduleLesson $lesson, bool $write): void
    {
        if ($write && ! $user->hasPermission('journal.edit')) {
            abort(403);
        }
        if ($user->hasRole('admin') || $user->hasPermission('journal.view_all')) {
            return;
        }
        $teacherId = Teacher::query()->where('user_id', $user->id)->value('id');
        if ($teacherId && (int) $lesson->teacher_id === (int) $teacherId) {
            return;
        }
        abort(403);
    }

    /**
     * Доступ к одному занятию.
     *
     * `$curatorMayRead` включается только там, где куратору действительно нужно
     * посмотреть занятие своей группы: карточка, вложение, выгрузка. На заявке
     * о правке и на подсказке по посещаемости он остаётся выключенным
     * намеренно — это инструменты того, кто ведёт занятие, и открывать их
     * куратору значит дать ему просить переоткрытие чужого журнала.
     *
     * Само правило живёт в `JournalLessonAccess`: его же спрашивает ресурс,
     * когда решает, показывать ли экрану кнопки правки.
     */
    private function authorizeLesson(User $user, JournalLesson $lesson, bool $write, bool $curatorMayRead = false): void
    {
        if ($write && ! $user->hasPermission('journal.edit')) {
            abort(403, 'Права на правку журнала нет.');
        }

        $allowed = $write
            ? $this->access->canEdit($user, $lesson)
            : $this->access->canRead($user, $lesson, $curatorMayRead);

        // Отказ обязан назвать причину. Пустой `403` доходит до телефона как
        // молчание: преподаватель видит занятие, нажимает отметку и не получает
        // ничего — ни отметки, ни объяснения.
        abort_unless($allowed, 403, $write
            ? 'Это занятие ведёт другой преподаватель: отметки ставит тот, за кем занятие закреплено в расписании.'
            : 'Это занятие не ваше и не вашей группы.');
    }

    private function filterStudentPayload(User $user, JournalLesson $lesson): void
    {
        if ($user->hasRole('admin') || $user->hasPermission('journal.view_all') || Teacher::query()->where('user_id', $user->id)->exists()) {
            return;
        }
        $student = Student::query()->where('user_id', $user->id)->first();
        if (! $student) {
            return;
        }
        $lesson->setRelation('attendance', $lesson->attendance->where('student_id', $student->id)->values());
        $lesson->setRelation('grades', $lesson->grades->where('student_id', $student->id)->values());
    }
}
