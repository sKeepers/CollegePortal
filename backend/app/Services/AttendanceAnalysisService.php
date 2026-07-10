<?php

namespace App\Services;

use App\Models\AccessEvent;
use App\Models\DigitalIdentity;
use App\Models\ScheduleLesson;
use App\Models\Student;
use App\Models\Teacher;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class AttendanceAnalysisService
{
    public function teachersToday(?CarbonImmutable $date = null): array
    {
        $date ??= CarbonImmutable::today();
        $lessonsByTeacher = $this->todayLessons($date)->groupBy('teacher_id');
        $eventsByOwner = $this->allowedEvents($date, DigitalIdentity::ENTITY_TEACHER)->groupBy('entity_id');
        $currentAcademicYear = (string) SettingService::value('academic', 'current_academic_year', '');

        $teachers = Teacher::query()
            ->with(['teachingLoads' => fn ($query) => $query->when($currentAcademicYear !== '', fn ($query) => $query->where('academic_year', $currentAcademicYear))->with('items')])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        return [
            'date' => $date->toDateString(),
            'data' => $teachers->map(fn (Teacher $teacher) => $this->teacherRow(
                $teacher,
                $lessonsByTeacher->get($teacher->id, collect()),
                $eventsByOwner->get($teacher->id, collect()),
                $date,
            ))->values()->all(),
            'summary' => $this->summary($teachers, $eventsByOwner, $lessonsByTeacher),
        ];
    }

    public function studentsToday(?CarbonImmutable $date = null): array
    {
        $date ??= CarbonImmutable::today();
        $lessonsByGroup = $this->todayLessons($date)->groupBy('group_id');
        $eventsByOwner = $this->allowedEvents($date, DigitalIdentity::ENTITY_STUDENT)->groupBy('entity_id');

        $students = Student::query()
            ->with('group')
            ->where('status', 'active')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        return [
            'date' => $date->toDateString(),
            'data' => $students->map(fn (Student $student) => $this->studentRow(
                $student,
                $lessonsByGroup->get($student->group_id, collect()),
                $eventsByOwner->get($student->id, collect()),
                $date,
            ))->values()->all(),
            'summary' => $this->summary($students, $eventsByOwner, collect()),
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
            'department' => $teacher->department,
            'status' => $status['code'],
            'status_label' => $status['label'],
            'status_tone' => $status['tone'],
            'first_entry' => $this->formatDateTime($firstEntry?->event_time),
            'last_exit' => $this->formatDateTime($lastExit?->event_time),
            'first_lesson' => $this->lessonPayload($firstLesson, $date),
            'late_minutes' => $lateMinutes,
            'inside_now' => $insideNow,
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
            'group' => $student->group?->name,
            'status' => $status['code'],
            'status_label' => $status['label'],
            'status_tone' => $status['tone'],
            'first_entry' => $this->formatDateTime($firstEntry?->event_time),
            'last_exit' => $this->formatDateTime($lastExit?->event_time),
            'first_lesson' => $this->lessonPayload($firstLesson, $date),
            'late_minutes' => $lateMinutes,
            'inside_now' => $insideNow,
            'comment' => $this->comment($status['code'], $firstLessonStart, $lateMinutes, $insideNow, false),
        ];
    }

    private function todayLessons(CarbonImmutable $date): EloquentCollection
    {
        return ScheduleLesson::query()
            ->with(['group', 'teacher', 'subject', 'classroom'])
            ->whereDate('lesson_date', $date->toDateString())
            ->orderBy('starts_at')
            ->get();
    }

    private function allowedEvents(CarbonImmutable $date, string $entityType): EloquentCollection
    {
        return AccessEvent::query()
            ->where('entity_type', $entityType)
            ->where('result', AccessEvent::RESULT_ALLOWED)
            ->whereBetween('event_time', [$date->startOfDay(), $date->endOfDay()])
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
            return ['code' => $personType === 'teacher' ? 'not_arrived' : 'not_entered', 'label' => $personType === 'teacher' ? 'Не пришел' : 'Не вошел', 'tone' => 'danger'];
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

        return $firstLessonStart->diffInMinutes($entry);
    }

    private function lessonStart(ScheduleLesson $lesson, CarbonImmutable $date): ?CarbonImmutable
    {
        $time = $this->formatTime($lesson->starts_at);

        if ($time === null) {
            return null;
        }

        return CarbonImmutable::parse($date->toDateString().' '.$time);
    }

    private function lessonPayload(?ScheduleLesson $lesson, CarbonImmutable $date): ?array
    {
        if ($lesson === null) {
            return null;
        }

        return [
            'id' => $lesson->id,
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

    private function comment(string $status, ?CarbonImmutable $firstLessonStart, ?int $lateMinutes, bool $insideNow, bool $hasTeachingLoad): string
    {
        if ($status === 'no_schedule') {
            return $hasTeachingLoad ? 'Сегодня занятий в расписании нет, нагрузка есть.' : 'Сегодня занятий в расписании нет.';
        }

        if (in_array($status, ['not_arrived', 'not_entered'], true)) {
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

    private function summary(Collection $people, Collection $eventsByOwner, Collection $lessonsByOwner): array
    {
        return [
            'total' => $people->count(),
            'with_events' => $eventsByOwner->count(),
            'with_schedule' => $lessonsByOwner->count(),
            'inside_now' => $eventsByOwner->filter(fn (Collection $events) => $this->insideNow($events))->count(),
        ];
    }
}
