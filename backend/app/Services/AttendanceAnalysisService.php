<?php

namespace App\Services;

use App\Models\AccessEvent;
use App\Models\DigitalIdentity;
use App\Models\ScheduleLesson;
use App\Models\Student;
use App\Models\Teacher;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class AttendanceAnalysisService
{
    public const TEACHER_ABSENT = 'not_arrived';
    public const STUDENT_ABSENT = 'not_entered';

    /** @param array<string, mixed> $filters */
    public function teachersToday(?CarbonImmutable $date = null, array $filters = []): array
    {
        $date ??= CarbonImmutable::today();

        return $this->teachers($filters + [
            'date_from' => $date->toDateString(),
            'date_to' => $date->toDateString(),
        ]);
    }

    /** @param array<string, mixed> $filters */
    public function studentsToday(?CarbonImmutable $date = null, array $filters = []): array
    {
        $date ??= CarbonImmutable::today();

        return $this->students($filters + [
            'date_from' => $date->toDateString(),
            'date_to' => $date->toDateString(),
        ]);
    }

    /** @param array<string, mixed> $filters */
    public function teachers(array $filters = []): array
    {
        [$dateFrom, $dateTo] = $this->dateRange($filters);
        $lessons = $this->lessons($dateFrom, $dateTo, $filters);
        $lessonsByTeacher = $lessons->groupBy('teacher_id');
        $eventsByOwner = $this->allowedEvents($dateFrom, $dateTo, DigitalIdentity::ENTITY_TEACHER)->groupBy('entity_id');
        $currentAcademicYear = (string) SettingService::value('academic', 'current_academic_year', '');

        $teachers = Teacher::query()
            ->with(['teachingLoads' => fn ($query) => $query->when($currentAcademicYear !== '', fn ($query) => $query->where('academic_year', $currentAcademicYear))->with('items')])
            ->when($filters['teacher_id'] ?? null, fn (Builder $query, mixed $teacherId) => $query->whereKey($teacherId))
            ->when($filters['group_id'] ?? null, fn (Builder $query, mixed $groupId) => $query->whereHas('scheduleLessons', fn (Builder $query) => $query->where('group_id', $groupId)->whereDate('lesson_date', '>=', $dateFrom->toDateString())->whereDate('lesson_date', '<=', $dateTo->toDateString())))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $rows = $teachers->map(fn (Teacher $teacher) => $this->teacherRow(
            $teacher,
            $lessonsByTeacher->get($teacher->id, collect()),
            $eventsByOwner->get($teacher->id, collect()),
            $dateFrom,
        ))->values();

        $rows = $this->filterRows($rows, $filters);

        return [
            'date' => $dateFrom->toDateString(),
            'date_from' => $dateFrom->toDateString(),
            'date_to' => $dateTo->toDateString(),
            'data' => $rows->values()->all(),
            'summary' => $this->rowSummary($rows, DigitalIdentity::ENTITY_TEACHER),
        ];
    }

    /** @param array<string, mixed> $filters */
    public function students(array $filters = []): array
    {
        [$dateFrom, $dateTo] = $this->dateRange($filters);
        $lessons = $this->lessons($dateFrom, $dateTo, $filters);
        $lessonsByGroup = $lessons->groupBy('group_id');
        $eventsByOwner = $this->allowedEvents($dateFrom, $dateTo, DigitalIdentity::ENTITY_STUDENT)->groupBy('entity_id');

        $students = Student::query()
            ->with('group')
            ->where('status', 'active')
            ->when($filters['group_id'] ?? null, fn (Builder $query, mixed $groupId) => $query->where('group_id', $groupId))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $rows = $students->map(fn (Student $student) => $this->studentRow(
            $student,
            $lessonsByGroup->get($student->group_id, collect()),
            $eventsByOwner->get($student->id, collect()),
            $dateFrom,
        ))->values();

        $rows = $this->filterRows($rows, $filters);

        return [
            'date' => $dateFrom->toDateString(),
            'date_from' => $dateFrom->toDateString(),
            'date_to' => $dateTo->toDateString(),
            'data' => $rows->values()->all(),
            'summary' => $this->rowSummary($rows, DigitalIdentity::ENTITY_STUDENT),
        ];
    }

    /** @return array<string, mixed> */
    public function dashboardSummary(): array
    {
        $teachers = collect($this->teachersToday()['data']);
        $students = collect($this->studentsToday()['data']);
        $studentThreshold = (int) SettingService::value('attendance', 'student_late_threshold_minutes', 10);

        return [
            'teachers' => $this->dashboardCounters($teachers, DigitalIdentity::ENTITY_TEACHER),
            'students' => $this->dashboardCounters($students, DigitalIdentity::ENTITY_STUDENT),
            'attention' => [
                'teachers_absent' => $this->attentionRows($teachers, [self::TEACHER_ABSENT], '/attendance?type=teachers&status=absent'),
                'teachers_late' => $this->attentionRows($teachers, ['late'], '/attendance?type=teachers&status=late'),
                'students_late_over_threshold' => $this->attentionRows($students->filter(fn (array $row) => ($row['late_minutes'] ?? 0) > $studentThreshold), ['late'], '/attendance?type=students&status=late'),
                'schedule_without_entry' => $this->attentionRows($students->merge($teachers), [self::TEACHER_ABSENT, self::STUDENT_ABSENT], '/attendance?status=absent'),
            ],
        ];
    }

    private function teacherRow(Teacher $teacher, Collection $lessons, Collection $events, CarbonImmutable $date): array
    {
        $firstLesson = $lessons->sortBy(fn (ScheduleLesson $lesson) => $this->lessonStart($lesson, $date)?->timestamp ?? PHP_INT_MAX)->first();
        $firstLessonStart = $firstLesson ? $this->lessonStart($firstLesson, $date) : null;
        $firstEntry = $this->firstEvent($events, AccessEvent::DIRECTION_IN);
        $lastExit = $this->lastEvent($events, AccessEvent::DIRECTION_OUT);
        $insideNow = $this->insideNow($events);
        $lateMinutes = $this->lateMinutes($firstEntry?->event_time, $firstLessonStart);
        $status = $this->statusForScheduledPerson($firstEntry?->event_time, $firstLessonStart, $insideNow, 'teacher');
        $loadHours = $teacher->teachingLoads->flatMap->items->sum('hours_total');

        return [
            'id' => 'teacher-'.$teacher->id,
            'entity_type' => DigitalIdentity::ENTITY_TEACHER,
            'entity_id' => $teacher->id,
            'full_name' => $this->fullName($teacher),
            'photo_url' => $this->photoUrl($teacher->photo_path),
            'department' => $teacher->department,
            'status' => $status['code'],
            'status_label' => $status['label'],
            'status_tone' => $status['tone'],
            'first_entry' => $this->formatDateTime($firstEntry?->event_time),
            'last_exit' => $this->formatDateTime($lastExit?->event_time),
            'first_lesson' => $this->lessonPayload($firstLesson, $date),
            'late_minutes' => $lateMinutes,
            'inside_now' => $insideNow,
            'minutes_inside' => $this->minutesInside($events),
            'teaching_load_hours' => $loadHours,
            'comment' => $this->comment($status['code'], $firstLessonStart, $lateMinutes, $insideNow, $teacher->teachingLoads->isNotEmpty()),
        ];
    }

    private function studentRow(Student $student, Collection $lessons, Collection $events, CarbonImmutable $date): array
    {
        $firstLesson = $lessons->sortBy(fn (ScheduleLesson $lesson) => $this->lessonStart($lesson, $date)?->timestamp ?? PHP_INT_MAX)->first();
        $firstLessonStart = $firstLesson ? $this->lessonStart($firstLesson, $date) : null;
        $firstEntry = $this->firstEvent($events, AccessEvent::DIRECTION_IN);
        $lastExit = $this->lastEvent($events, AccessEvent::DIRECTION_OUT);
        $insideNow = $this->insideNow($events);
        $lateMinutes = $this->lateMinutes($firstEntry?->event_time, $firstLessonStart);
        $status = $this->statusForScheduledPerson($firstEntry?->event_time, $firstLessonStart, $insideNow, 'student');

        return [
            'id' => 'student-'.$student->id,
            'entity_type' => DigitalIdentity::ENTITY_STUDENT,
            'entity_id' => $student->id,
            'full_name' => $this->fullName($student),
            'photo_url' => $this->photoUrl($student->photo_path),
            'group_id' => $student->group_id,
            'group' => $student->group?->name,
            'status' => $status['code'],
            'status_label' => $status['label'],
            'status_tone' => $status['tone'],
            'first_entry' => $this->formatDateTime($firstEntry?->event_time),
            'last_exit' => $this->formatDateTime($lastExit?->event_time),
            'first_lesson' => $this->lessonPayload($firstLesson, $date),
            'late_minutes' => $lateMinutes,
            'inside_now' => $insideNow,
            'minutes_inside' => $this->minutesInside($events),
            'comment' => $this->comment($status['code'], $firstLessonStart, $lateMinutes, $insideNow, false),
        ];
    }

    private function lessons(CarbonImmutable $dateFrom, CarbonImmutable $dateTo, array $filters): EloquentCollection
    {
        return ScheduleLesson::query()
            ->with(['group', 'teacher', 'subject', 'classroom'])
            ->whereDate('lesson_date', '>=', $dateFrom->toDateString())
            ->whereDate('lesson_date', '<=', $dateTo->toDateString())
            ->when($filters['group_id'] ?? null, fn (Builder $query, mixed $groupId) => $query->where('group_id', $groupId))
            ->when($filters['teacher_id'] ?? null, fn (Builder $query, mixed $teacherId) => $query->where('teacher_id', $teacherId))
            ->orderBy('lesson_date')
            ->orderBy('starts_at')
            ->get();
    }

    private function allowedEvents(CarbonImmutable $dateFrom, CarbonImmutable $dateTo, string $entityType): EloquentCollection
    {
        return AccessEvent::query()
            ->where('entity_type', $entityType)
            ->where('result', AccessEvent::RESULT_ALLOWED)
            ->whereBetween('event_time', [$dateFrom->startOfDay(), $dateTo->endOfDay()])
            ->orderBy('event_time')
            ->get();
    }

    private function firstEvent(Collection $events, string $direction): ?AccessEvent
    {
        return $events->where('direction', $direction)->sortBy('event_time')->first();
    }

    private function lastEvent(Collection $events, string $direction): ?AccessEvent
    {
        return $events->where('direction', $direction)->sortByDesc('event_time')->first();
    }

    private function insideNow(Collection $events): bool
    {
        $last = $events->sortByDesc('event_time')->first();

        return $last?->direction === AccessEvent::DIRECTION_IN;
    }

    private function statusForScheduledPerson(?DateTimeInterface $firstEntry, ?CarbonImmutable $firstLessonStart, bool $insideNow, string $personType): array
    {
        if ($firstLessonStart === null) {
            if ($firstEntry !== null) {
                return ['code' => 'entered', 'label' => $personType === 'teacher' ? 'Пришел' : 'Вошел', 'tone' => 'info'];
            }

            return ['code' => 'no_schedule', 'label' => 'Нет занятий', 'tone' => 'neutral'];
        }

        if ($firstEntry === null) {
            return ['code' => $personType === 'teacher' ? self::TEACHER_ABSENT : self::STUDENT_ABSENT, 'label' => $personType === 'teacher' ? 'Не пришел' : 'Не вошел', 'tone' => 'danger'];
        }

        $entry = CarbonImmutable::instance($firstEntry);

        if ($entry->greaterThan($firstLessonStart)) {
            return ['code' => 'late', 'label' => 'Опоздал', 'tone' => 'warning'];
        }

        if ($entry->lessThan($firstLessonStart)) {
            return ['code' => 'early', 'label' => 'Пришел заранее', 'tone' => 'success'];
        }

        return ['code' => $insideNow ? 'present' : 'arrived', 'label' => $personType === 'teacher' ? 'Пришел' : 'Вошел', 'tone' => 'success'];
    }

    private function lateMinutes(?DateTimeInterface $firstEntry, ?CarbonImmutable $firstLessonStart): ?int
    {
        if ($firstEntry === null || $firstLessonStart === null) {
            return null;
        }

        $entry = CarbonImmutable::instance($firstEntry);

        if ($entry->lessThanOrEqualTo($firstLessonStart)) {
            return 0;
        }

        return (int) $firstLessonStart->diffInMinutes($entry);
    }

    private function minutesInside(Collection $events): int
    {
        $minutes = 0;
        $openEntry = null;

        foreach ($events->sortBy('event_time') as $event) {
            if ($event->direction === AccessEvent::DIRECTION_IN) {
                $openEntry = CarbonImmutable::instance($event->event_time);
                continue;
            }

            if ($event->direction === AccessEvent::DIRECTION_OUT && $openEntry !== null) {
                $minutes += (int) $openEntry->diffInMinutes(CarbonImmutable::instance($event->event_time));
                $openEntry = null;
            }
        }

        if ($openEntry !== null) {
            $minutes += (int) $openEntry->diffInMinutes(now());
        }

        return max(0, $minutes);
    }

    private function lessonStart(ScheduleLesson $lesson, CarbonImmutable $date): ?CarbonImmutable
    {
        $time = $this->formatTime($lesson->starts_at);
        $lessonDate = $lesson->lesson_date?->toDateString() ?: $date->toDateString();

        return $time ? CarbonImmutable::parse($lessonDate.' '.$time) : null;
    }

    private function lessonPayload(?ScheduleLesson $lesson, CarbonImmutable $date): ?array
    {
        if ($lesson === null) {
            return null;
        }

        return [
            'id' => $lesson->id,
            'date' => $lesson->lesson_date?->toDateString(),
            'starts_at' => $this->formatTime($lesson->starts_at),
            'ends_at' => $this->formatTime($lesson->ends_at),
            'subject' => $lesson->subject?->name,
            'group' => $lesson->group?->name,
            'classroom' => $lesson->classroom?->number,
            'starts_at_iso' => $this->lessonStart($lesson, $date)?->toISOString(),
        ];
    }

    private function formatTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('H:i');
        }

        return substr((string) $value, 0, 5);
    }

    private function formatDateTime(?DateTimeInterface $value): ?string
    {
        return $value ? CarbonImmutable::instance($value)->toISOString() : null;
    }

    private function fullName(object $person): string
    {
        return collect([$person->last_name ?? null, $person->first_name ?? null, $person->middle_name ?? null])
            ->filter()
            ->implode(' ');
    }

    private function photoUrl(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }

    private function comment(string $status, ?CarbonImmutable $firstLessonStart, ?int $lateMinutes, bool $insideNow, bool $hasTeachingLoad): string
    {
        if ($status === 'no_schedule') {
            return $hasTeachingLoad ? 'Сегодня занятий в расписании нет, нагрузка есть.' : 'Сегодня занятий в расписании нет.';
        }

        if (in_array($status, [self::TEACHER_ABSENT, self::STUDENT_ABSENT], true)) {
            return $firstLessonStart ? 'Нет входа перед первой парой.' : 'Нет событий проходной.';
        }

        if ($status === 'late') {
            return 'Опоздание: '.($lateMinutes ?? 0).' мин.';
        }

        if ($status === 'early') {
            return $insideNow ? 'В колледже, вход до начала первой пары.' : 'Вход был до начала первой пары.';
        }

        return $insideNow ? 'Сейчас в здании.' : 'Вход зафиксирован, сейчас не в здании.';
    }

    private function dateRange(array $filters): array
    {
        $from = CarbonImmutable::parse((string) ($filters['date_from'] ?? now()->toDateString()))->startOfDay();
        $to = CarbonImmutable::parse((string) ($filters['date_to'] ?? $from->toDateString()))->startOfDay();

        if ($to->lessThan($from)) {
            $to = $from;
        }

        return [$from, $to];
    }

    private function filterRows(Collection $rows, array $filters): Collection
    {
        $status = (string) ($filters['status'] ?? '');

        if ($status === '') {
            return $rows;
        }

        $statuses = match ($status) {
            'absent' => [self::TEACHER_ABSENT, self::STUDENT_ABSENT],
            'on_time' => ['present', 'arrived', 'early', 'entered'],
            default => [$status],
        };

        return $rows->filter(fn (array $row) => in_array($row['status'], $statuses, true));
    }

    private function rowSummary(Collection $rows, string $entityType): array
    {
        $absentStatus = $entityType === DigitalIdentity::ENTITY_TEACHER ? self::TEACHER_ABSENT : self::STUDENT_ABSENT;

        return [
            'total' => $rows->count(),
            'with_events' => $rows->filter(fn (array $row) => $row['first_entry'] !== null)->count(),
            'with_schedule' => $rows->filter(fn (array $row) => $row['first_lesson'] !== null)->count(),
            'inside_now' => $rows->where('inside_now', true)->count(),
            'late' => $rows->where('status', 'late')->count(),
            'absent' => $rows->where('status', $absentStatus)->count(),
            'on_time' => $rows->filter(fn (array $row) => in_array($row['status'], ['present', 'arrived', 'early', 'entered'], true))->count(),
        ];
    }

    private function dashboardCounters(Collection $rows, string $entityType): array
    {
        $summary = $this->rowSummary($rows, $entityType);

        return $entityType === DigitalIdentity::ENTITY_TEACHER
            ? [
                'on_time' => $summary['on_time'],
                'late' => $summary['late'],
                'absent' => $summary['absent'],
                'inside_now' => $summary['inside_now'],
            ]
            : [
                'entered' => $summary['with_events'],
                'late' => $summary['late'],
                'absent' => $summary['absent'],
                'inside_now' => $summary['inside_now'],
            ];
    }

    private function attentionRows(Collection $rows, array $statuses, string $to): array
    {
        return [
            'count' => $rows->filter(fn (array $row) => in_array($row['status'], $statuses, true))->count(),
            'items' => $rows
                ->filter(fn (array $row) => in_array($row['status'], $statuses, true))
                ->sortByDesc(fn (array $row) => $row['late_minutes'] ?? 0)
                ->take(5)
                ->map(fn (array $row) => [
                    'id' => $row['id'],
                    'name' => $row['full_name'],
                    'status' => $row['status_label'],
                    'late_minutes' => $row['late_minutes'],
                    'to' => $to,
                ])
                ->values()
                ->all(),
            'to' => $to,
        ];
    }
}
