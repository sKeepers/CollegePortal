<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\JournalLessonFileResource;
use App\Http\Resources\JournalLessonResource;
use App\Models\JournalLesson;
use App\Models\JournalLessonFile;
use App\Models\ScheduleEntry;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\JournalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JournalLessonController extends Controller
{
    public function __construct(private readonly JournalService $journalService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = JournalLesson::query()
            ->with(['group', 'subject', 'teacher', 'lessonType', 'scheduleEntry.classroom'])
            ->when($request->integer('group_id'), fn (Builder $q, int $id) => $q->where('group_id', $id))
            ->when($request->integer('subject_id'), fn (Builder $q, int $id) => $q->where('subject_id', $id))
            ->when($request->integer('teacher_id'), fn (Builder $q, int $id) => $q->where('teacher_id', $id))
            ->when($request->string('status')->toString(), fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($request->date('date'), fn (Builder $q, $date) => $q->whereDate('lesson_date', $date))
            ->when($request->string('mode')->toString() === 'today', fn (Builder $q) => $q->whereDate('lesson_date', today()))
            ->when($request->string('mode')->toString() === 'needs_fill', fn (Builder $q) => $q->whereIn('status', ['planned', 'opened', 'completed']))
            ->when($request->string('mode')->toString() === 'signed', fn (Builder $q) => $q->where('status', 'signed'));

        $this->applyScope($query, $request->user());

        return JournalLessonResource::collection($query->orderByDesc('lesson_date')->orderBy('starts_at')->paginate($request->integer('per_page') ?: 20));
    }

    public function show(Request $request, JournalLesson $lesson): JournalLessonResource
    {
        $this->authorizeLesson($request->user(), $lesson, false);
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

    public function update(Request $request, JournalLesson $lesson): JournalLessonResource
    {
        $this->authorizeLesson($request->user(), $lesson, true);
        $data = $request->validate([
            'topic' => ['nullable', 'string', 'max:2000'],
            'homework' => ['nullable', 'string', 'max:2000'],
            'teacher_comment' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in(['planned', 'opened', 'completed', 'signed', 'cancelled'])],
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
        $this->authorizeLesson($request->user(), $lesson, false);
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
        $this->authorizeLesson($request->user(), $lesson, false);
        $lesson = $this->journalService->loadLesson($lesson);

        return response()->streamDownload(function () use ($lesson): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['student', 'attendance', 'minutes_late', 'grade', 'comment']);
            foreach ($lesson->attendance as $attendance) {
                $grade = $lesson->grades->firstWhere('student_id', $attendance->student_id);
                $student = $attendance->student;
                fputcsv($out, [
                    trim("{$student->last_name} {$student->first_name} {$student->middle_name}"),
                    $attendance->status,
                    $attendance->minutes_late,
                    $grade?->value,
                    $attendance->comment,
                ]);
            }
            fclose($out);
        }, "journal-lesson-{$lesson->id}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function applyScope(Builder $query, User $user): void
    {
        if ($user->hasPermission('journal.view_all')) {
            return;
        }

        if ($teacherId = Teacher::query()->where('user_id', $user->id)->value('id')) {
            $query->where('teacher_id', $teacherId);
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

    private function authorizeLesson(User $user, JournalLesson $lesson, bool $write): void
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
        if (! $write && ($student = Student::query()->where('user_id', $user->id)->first())) {
            if ((int) $student->group_id === (int) $lesson->group_id) {
                return;
            }
        }
        abort(403);
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
