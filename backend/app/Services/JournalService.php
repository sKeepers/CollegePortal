<?php

namespace App\Services;

use App\Models\AccessEvent;
use App\Models\DigitalIdentity;
use App\Models\JournalAttendance;
use App\Models\JournalGrade;
use App\Models\JournalLesson;
use App\Models\JournalLessonFile;
use App\Models\ScheduleEntry;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class JournalService
{
    private const ATTENDANCE_STATUSES = ['present', 'absent', 'late', 'excused', 'sick', 'remote'];
    private const GRADE_VALUES = ['2', '3', '4', '5', 'зачет', 'незачет', 'освобожден', 'освобождён', 'не аттестован', ''];

    public function openFromSchedule(ScheduleEntry $entry, User $user): JournalLesson
    {
        if ($entry->status === 'cancelled') {
            throw ValidationException::withMessages([
                'schedule_entry_id' => ['Отмененное занятие не открывается как обычный журнал.'],
            ]);
        }

        return DB::transaction(function () use ($entry, $user): JournalLesson {
            $lesson = JournalLesson::query()->firstOrCreate(
                ['schedule_entry_id' => $entry->id],
                [
                    'group_id' => $entry->group_id,
                    'subject_id' => $entry->subject_id,
                    'teacher_id' => $entry->teacher_id,
                    'lesson_date' => $entry->date ?? now()->toDateString(),
                    'starts_at' => $this->timeValue($entry->starts_at),
                    'ends_at' => $this->timeValue($entry->ends_at),
                    'lesson_type_id' => $entry->lesson_type_id,
                    'teacher_comment' => $entry->comment,
                    'status' => JournalLesson::STATUS_IN_PROGRESS,
                    'opened_at' => now(),
                ],
            );

            if ($lesson->wasRecentlyCreated) {
                AuditLogService::log('journal', 'open_from_schedule', $lesson, null, $lesson->toArray(), request(), $user);
            }

            $this->ensureAttendanceRoster($lesson, $user);

            return $this->loadLesson($lesson->refresh());
        });
    }

    public function loadLesson(JournalLesson $lesson): JournalLesson
    {
        return $lesson->load([
            'group', 'subject', 'teacher', 'lessonType', 'signedBy', 'reopenedBy', 'scheduleEntry.group', 'scheduleEntry.subject', 'scheduleEntry.teacher', 'scheduleEntry.classroom', 'scheduleEntry.lessonType',
            'attendance.student.group', 'grades.student.group', 'grades.gradeType', 'files',
        ]);
    }

    public function ensureAttendanceRoster(JournalLesson $lesson, ?User $user = null): void
    {
        Student::query()
            ->where('group_id', $lesson->group_id)
            ->where(function (Builder $query): void {
                $query->whereNull('archived_at')->where(function (Builder $inner): void {
                    $inner->whereNull('status')->orWhereNotIn('status', ['archived', 'expelled']);
                });
            })
            ->orderBy('last_name')
            ->get()
            ->each(function (Student $student) use ($lesson, $user): void {
                JournalAttendance::query()->firstOrCreate(
                    ['journal_lesson_id' => $lesson->id, 'student_id' => $student->id],
                    ['status' => JournalAttendance::STATUS_PRESENT, 'source' => 'roster', 'marked_by' => $user?->id, 'marked_at' => now()],
                );
            });
    }

    public function updateLesson(JournalLesson $lesson, array $data, User $user): JournalLesson
    {
        $this->guardSigned($lesson, $user);
        $old = $lesson->only(['topic', 'homework', 'homework_due_at', 'teacher_comment', 'status']);
        $lesson->fill(collect($data)->only(['topic', 'homework', 'homework_due_at', 'teacher_comment', 'status'])->all());
        if (in_array($lesson->status, [JournalLesson::STATUS_PLANNED, JournalLesson::STATUS_DRAFT], true)) {
            $lesson->status = JournalLesson::STATUS_IN_PROGRESS;
            $lesson->opened_at ??= now();
        }
        $lesson->save();
        AuditLogService::log('journal', 'update_lesson', $lesson, $old, $lesson->only(['topic', 'homework', 'homework_due_at', 'teacher_comment', 'status']), request(), $user);

        return $this->loadLesson($lesson->refresh());
    }

    public function saveAttendance(JournalLesson $lesson, array $rows, User $user): JournalLesson
    {
        $this->guardSigned($lesson, $user);
        DB::transaction(function () use ($lesson, $rows, $user): void {
            foreach ($rows as $row) {
                $studentId = (int) ($row['student_id'] ?? 0);
                $this->ensureStudentBelongsToLesson($lesson, $studentId);
                $status = (string) ($row['status'] ?? JournalAttendance::STATUS_PRESENT);
                if (! in_array($status, self::ATTENDANCE_STATUSES, true)) {
                    throw ValidationException::withMessages(['status' => ['Недопустимый статус посещаемости.']]);
                }
                $attendance = JournalAttendance::query()->firstOrNew([
                    'journal_lesson_id' => $lesson->id,
                    'student_id' => $studentId,
                ]);
                $old = $attendance->exists ? $attendance->getAttributes() : null;
                $attendance->fill([
                    'status' => $status,
                    'minutes_late' => $row['minutes_late'] ?? null,
                    'comment' => $row['comment'] ?? null,
                    'source' => $row['source'] ?? 'manual',
                    'marked_by' => $user->id,
                    'marked_at' => now(),
                ])->save();
                AuditLogService::log('journal', 'attendance_update', $attendance, $old, $attendance->getAttributes(), request(), $user);
            }
        });

        return $this->loadLesson($lesson->refresh());
    }

    public function saveGrades(JournalLesson $lesson, array $rows, User $user): JournalLesson
    {
        $this->guardSigned($lesson, $user);
        DB::transaction(function () use ($lesson, $rows, $user): void {
            foreach ($rows as $row) {
                $studentId = (int) ($row['student_id'] ?? 0);
                $this->ensureStudentBelongsToLesson($lesson, $studentId);
                $value = trim((string) ($row['value'] ?? ''));
                if (! in_array($value, self::GRADE_VALUES, true)) {
                    throw ValidationException::withMessages(['value' => ['Недопустимое значение оценки.']]);
                }
                $gradeTypeId = $row['grade_type_id'] ?? null;
                $grade = JournalGrade::query()->firstOrNew([
                    'journal_lesson_id' => $lesson->id,
                    'student_id' => $studentId,
                    'grade_type_id' => $gradeTypeId,
                ]);
                $old = $grade->exists ? $grade->getAttributes() : null;
                $grade->fill([
                    'value' => $value === '' ? null : $value,
                    'weight' => $row['weight'] ?? null,
                    'comment' => $row['comment'] ?? null,
                    'marked_by' => $user->id,
                    'marked_at' => now(),
                ])->save();
                AuditLogService::log('journal', 'grade_update', $grade, $old, $grade->getAttributes(), request(), $user);
            }
        });

        return $this->loadLesson($lesson->refresh());
    }

    public function complete(JournalLesson $lesson, User $user): JournalLesson
    {
        $this->guardSigned($lesson, $user);
        $old = $lesson->getAttributes();
        $lesson->update(['status' => JournalLesson::STATUS_COMPLETED, 'completed_at' => now()]);
        AuditLogService::log('journal', 'complete', $lesson, $old, $lesson->getAttributes(), request(), $user);

        return $this->loadLesson($lesson->refresh());
    }

    public function sign(JournalLesson $lesson, User $user): JournalLesson
    {
        $this->guardSigned($lesson, $user);
        if (blank($lesson->topic)) {
            throw ValidationException::withMessages(['topic' => ['Перед подписью нужно заполнить тему занятия.']]);
        }
        if ($lesson->attendance()->count() === 0) {
            throw ValidationException::withMessages(['attendance' => ['Перед подписью нужно заполнить посещаемость.']]);
        }
        $old = $lesson->getAttributes();
        $lesson->update(['status' => JournalLesson::STATUS_SIGNED, 'signed_at' => now(), 'signed_by' => $user->id]);
        AuditLogService::log('journal', 'sign', $lesson, $old, $lesson->getAttributes(), request(), $user);

        return $this->loadLesson($lesson->refresh());
    }

    public function reopen(JournalLesson $lesson, User $user, string $reason): JournalLesson
    {
        if (! $user->hasPermission('journal.reopen')) {
            throw ValidationException::withMessages(['permission' => ['Недостаточно прав для повторного открытия журнала.']]);
        }
        $old = $lesson->getAttributes();
        $lesson->update([
            'status' => JournalLesson::STATUS_REOPENED,
            'reopened_at' => now(),
            'reopened_by' => $user->id,
            'reopen_reason' => $reason,
        ]);
        AuditLogService::log('journal', 'reopen', $lesson, $old, $lesson->getAttributes(), request(), $user);

        return $this->loadLesson($lesson->refresh());
    }

    public function attendanceSuggestion(JournalLesson $lesson): array
    {
        $lessonStart = Carbon::parse($lesson->lesson_date->toDateString().' '.$this->timeValue($lesson->starts_at));
        $lessonEnd = Carbon::parse($lesson->lesson_date->toDateString().' '.$this->timeValue($lesson->ends_at));

        return Student::query()->where('group_id', $lesson->group_id)->orderBy('last_name')->get()->map(function (Student $student) use ($lessonStart, $lessonEnd): array {
            $events = AccessEvent::query()
                ->where(function ($query) use ($student): void {
                    if ($student->person_id) {
                        $query->where('person_id', $student->person_id);
                    }
                    $query->orWhere(function ($legacyQuery) use ($student): void {
                        $legacyQuery->where('entity_type', DigitalIdentity::ENTITY_STUDENT)
                            ->where('entity_id', $student->id);
                    });
                })
                ->where('result', AccessEvent::RESULT_ALLOWED)
                ->whereBetween('event_time', [$lessonStart->copy()->subHours(4), $lessonEnd->copy()->addHours(1)])
                ->orderBy('event_time')
                ->get();
            $entryDirections = [AccessEvent::DIRECTION_ENTRY, 'in'];
            $exitDirections = [AccessEvent::DIRECTION_EXIT, 'out'];
            $firstIn = $events->first(fn (AccessEvent $event): bool => in_array($event->direction, $entryDirections, true));
            $lastOut = $events->filter(fn (AccessEvent $event): bool => in_array($event->direction, $exitDirections, true))->last();
            $status = 'no_data';
            $minutesLate = null;
            if ($firstIn) {
                $entered = Carbon::parse($firstIn->event_time);
                if ($entered->lte($lessonStart)) {
                    $status = 'probably_present';
                } elseif ($entered->lte($lessonEnd)) {
                    $status = 'probably_late';
                    $minutesLate = $lessonStart->diffInMinutes($entered);
                } else {
                    $status = 'probably_absent';
                }
            } elseif ($events->isEmpty()) {
                $status = 'no_data';
            }

            return [
                'student_id' => $student->id,
                'student_name' => trim("{$student->last_name} {$student->first_name} {$student->middle_name}"),
                'suggestion' => $status,
                'status' => match ($status) {
                    'probably_present' => JournalAttendance::STATUS_PRESENT,
                    'probably_late' => JournalAttendance::STATUS_LATE,
                    'probably_absent' => JournalAttendance::STATUS_ABSENT,
                    default => null,
                },
                'minutes_late' => $minutesLate,
                'first_in' => $firstIn?->event_time?->toISOString(),
                'last_out' => $lastOut?->event_time?->toISOString(),
                'left_before_end' => $lastOut ? Carbon::parse($lastOut->event_time)->lt($lessonEnd) : false,
            ];
        })->values()->all();
    }

    public function applyAttendanceSuggestion(JournalLesson $lesson, User $user): JournalLesson
    {
        $rows = collect($this->attendanceSuggestion($lesson))
            ->filter(fn (array $row): bool => $row['status'] !== null)
            ->map(fn (array $row): array => [
                'student_id' => $row['student_id'],
                'status' => $row['status'],
                'minutes_late' => $row['minutes_late'],
                'source' => 'access_gate_suggestion',
            ])->values()->all();

        return $this->saveAttendance($lesson, $rows, $user);
    }

    public function storeFile(JournalLesson $lesson, UploadedFile $file, User $user): JournalLessonFile
    {
        $this->guardSigned($lesson, $user);
        $path = $file->store("journal-lessons/{$lesson->id}", 'local');
        $record = JournalLessonFile::create([
            'journal_lesson_id' => $lesson->id,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize() ?: 0,
            'uploaded_by' => $user->id,
        ]);
        AuditLogService::log('journal', 'file_upload', $record, null, $record->only(['id', 'original_name', 'mime_type', 'size_bytes']), request(), $user);

        return $record;
    }

    public function deleteFile(JournalLessonFile $file, User $user): void
    {
        $lesson = $file->journalLesson;
        $this->guardSigned($lesson, $user);
        $old = $file->only(['id', 'journal_lesson_id', 'original_name', 'mime_type', 'size_bytes']);
        Storage::disk('local')->delete($file->path);
        $file->delete();
        AuditLogService::log('journal', 'file_delete', ['type' => 'JournalLessonFile', 'id' => $old['id']], $old, null, request(), $user);
    }

    public function guardSigned(JournalLesson $lesson, User $user): void
    {
        if ($lesson->isSigned() && ! $user->hasPermission('journal.reopen')) {
            throw ValidationException::withMessages([
                'journal_lesson_id' => ['Подписанное занятие нельзя изменить без права journal.reopen.'],
            ]);
        }
    }

    public function ensureStudentBelongsToLesson(JournalLesson $lesson, int $studentId): void
    {
        $exists = Student::query()->whereKey($studentId)->where('group_id', $lesson->group_id)->exists();
        if (! $exists) {
            throw ValidationException::withMessages(['student_id' => ['Студент не относится к группе занятия.']]);
        }
    }

    private function timeValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i:s');
        }
        return substr((string) $value, 0, 8);
    }
}
