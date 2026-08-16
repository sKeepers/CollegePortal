<?php

namespace App\Services;

use App\Models\AccessEvent;
use App\Models\DigitalIdentity;
use App\Models\Employee;
use App\Models\JournalAttendance;
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

    /** Графики сотрудников на время одного отчёта: в истории они спрашиваются на каждого человека. */
    private ?Collection $scheduleCache = null;

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
        $schedules = $this->workSchedules();
        $currentAcademicYear = (string) SettingService::value('academic', 'current_academic_year', '');

        $teachers = Teacher::query()
            ->with(['teachingLoads' => fn ($query) => $query->when($currentAcademicYear !== '', fn ($query) => $query->where('academic_year', $currentAcademicYear))->with('items')])
            ->when($filters['teacher_id'] ?? null, fn (Builder $query, mixed $teacherId) => $query->whereKey($teacherId))
            ->when($filters['group_id'] ?? null, fn (Builder $query, mixed $groupId) => $query->whereHas('scheduleLessons', fn (Builder $query) => $query->where('group_id', $groupId)->whereDate('lesson_date', '>=', $dateFrom->toDateString())->whereDate('lesson_date', '<=', $dateTo->toDateString())))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        // События спрашиваются после людей и только про них. Пока запрос шёл
        // раньше отбора, он поднимал проходы всего колледжа: замер 15.08.2026 на
        // стенде — 5736 строк ради 210, то есть 3,7 %. Цена росла вместе с
        // общим потоком проходной, а не с тем, что спросили на экране.
        $eventsByOwner = $this->allowedEvents($dateFrom, $dateTo, DigitalIdentity::ENTITY_TEACHER, $teachers->pluck('id'));

        $rows = $teachers->map(fn (Teacher $teacher) => $this->teacherRow(
            $teacher,
            $lessonsByTeacher->get($teacher->id, collect()),
            $eventsByOwner->get($teacher->id, collect()),
            $dateFrom,
            $schedules[$teacher->person_id] ?? null,
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
        $markedPresent = $this->studentsMarkedPresent($dateFrom, $dateTo);

        $students = Student::query()
            ->with('group')
            ->where('status', 'active')
            ->when($filters['group_id'] ?? null, fn (Builder $query, mixed $groupId) => $query->where('group_id', $groupId))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        // См. пояснение в `teachers()`: события спрашиваются про уже отобранных
        // людей, иначе экран одной группы поднимает проходную целиком.
        $eventsByOwner = $this->allowedEvents($dateFrom, $dateTo, DigitalIdentity::ENTITY_STUDENT, $students->pluck('id'));

        $rows = $students->map(fn (Student $student) => $this->studentRow(
            $student,
            $lessonsByGroup->get($student->group_id, collect()),
            $eventsByOwner->get($student->id, collect()),
            $dateFrom,
            $markedPresent->has($student->id),
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

        return [
            'teachers' => $this->dashboardCounters($teachers, DigitalIdentity::ENTITY_TEACHER),
            'students' => $this->dashboardCounters($students, DigitalIdentity::ENTITY_STUDENT),
            'attention' => [
                'teachers_absent' => $this->attentionRows($teachers, [self::TEACHER_ABSENT], '/attendance?type=teachers&status=absent'),
                'teachers_late' => $this->attentionRows($teachers, ['late'], '/attendance?type=teachers&status=late'),
                // Порог больше не применяется здесь вторым слоем: с 17.08.2026
                // его учитывает сам разбор, и статус «Опоздал» уже означает
                // «позже начала занятия больше, чем на порог». Пока порог жил
                // только тут, панель директора его слушалась, а экран
                // посещаемости и отчёты — нет, и два экрана расходились.
                'students_late_over_threshold' => $this->attentionRows($students, ['late'], '/attendance?type=students&status=late'),
                'schedule_without_entry' => $this->attentionRows($students->merge($teachers), [self::TEACHER_ABSENT, self::STUDENT_ABSENT], '/attendance?status=absent'),
                // Отмечен на занятии, а в здание не входил. Это не про
                // дисциплину студента, а про достоверность журнала, поэтому
                // блок отдельный и виден директору сразу.
                'marked_present_without_entry' => $this->markedWithoutEntryRows($students),
            ],
        ];
    }

    /** @param array{code: ?string, employment: ?string}|null $schedule */
    private function teacherRow(Teacher $teacher, Collection $lessons, Collection $events, CarbonImmutable $date, ?array $schedule = null): array
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
            // График рабочего дня: приходящий преподаватель провёл занятие и
            // ушёл — это норма, а не ранний уход, и отчёт обязан отличать его
            // от того, кто должен быть в колледже весь день.
            'work_schedule' => $schedule['code'] ?? null,
            'work_schedule_label' => $this->workScheduleLabel($schedule),
            'is_visiting' => $this->isVisiting($schedule),
            'comment' => $this->comment($status['code'], $firstLessonStart, $lateMinutes, $insideNow, $teacher->teachingLoads->isNotEmpty()),
        ];
    }

    private function studentRow(Student $student, Collection $lessons, Collection $events, CarbonImmutable $date, bool $markedPresent = false): array
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
            // Преподаватель отметил студента на занятии, а в здание он не
            // входил. Одно из двух неверно: либо человек прошёл мимо турникета,
            // либо отметка поставлена не глядя. Куратор и директор должны это
            // видеть, а не узнавать в конце семестра.
            'marked_present_without_entry' => $markedPresent && $firstEntry === null,
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

    /**
     * Проходы уже отобранных людей, сгруппированные по владельцу.
     *
     * Идентификаторы обязательны: без них запрос отбирал по типу и результату, а
     * по группе — нет, и экран куратора на двадцать человек читал проходы всего
     * колледжа. Пустой список людей значит пустой ответ, а не «все».
     *
     * @param  \Illuminate\Support\Collection<int, int>  $ownerIds
     * @return \Illuminate\Support\Collection<int, EloquentCollection<int, AccessEvent>>
     */
    private function allowedEvents(CarbonImmutable $dateFrom, CarbonImmutable $dateTo, string $entityType, Collection $ownerIds): Collection
    {
        if ($ownerIds->isEmpty()) {
            return collect();
        }

        return AccessEvent::query()
            ->where('entity_type', $entityType)
            ->where('result', AccessEvent::RESULT_ALLOWED)
            ->whereIn('entity_id', $ownerIds)
            ->whereBetween('event_time', [$dateFrom->startOfDay(), $dateTo->endOfDay()])
            ->orderBy('event_time')
            ->get()
            ->groupBy('entity_id');
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

        // Порог опоздания задаёт заместитель директора в настройках. До
        // 17.08.2026 он здесь не спрашивался вовсе: опоздавшим считался всякий,
        // кто вошёл позже начала занятия хоть на секунду, а три настройки в
        // каталоге с подписью «используется в аналитике посещаемости» не влияли
        // ни на что. Человек ставил 15 минут, сохранял и ничего не менялось.
        if ($entry->greaterThan($firstLessonStart->addMinutes($this->lateThresholdMinutes($personType)))) {
            return ['code' => 'late', 'label' => 'Опоздал', 'tone' => 'warning'];
        }

        if ($entry->lessThan($firstLessonStart)) {
            return ['code' => 'early', 'label' => 'Пришел заранее', 'tone' => 'success'];
        }

        return ['code' => $insideNow ? 'present' : 'arrived', 'label' => $personType === 'teacher' ? 'Пришел' : 'Вошел', 'tone' => 'success'];
    }

    /** @var array<string, int> прочитанные пороги, по одному чтению на разбор */
    private array $lateThresholds = [];

    /**
     * Сколько минут опоздания прощается.
     *
     * Значения приходят из настроек и по умолчанию разные: преподавателю пять
     * минут, студенту десять. Так и задумано в каталоге — преподаватель ведёт
     * занятие, и его опоздание дороже.
     *
     * Значение запоминается на время разбора: `students()` и `teachers()`
     * строят сотни строк, а внутри одного ответа порог меняться не может.
     */
    private function lateThresholdMinutes(string $personType): int
    {
        return $this->lateThresholds[$personType] ??= max(0, (int) SettingService::value(
            'attendance',
            $personType === 'teacher' ? 'teacher_late_threshold_minutes' : 'student_late_threshold_minutes',
            $personType === 'teacher' ? 5 : 10,
        ));
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
            'starts_at_iso' => $this->formatDateTime($this->lessonStart($lesson, $date)),
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
        return $value ? CarbonImmutable::instance($value)->format('Y-m-d\TH:i:s') : null;
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
        // Отдельный фильтр, а не статус: расхождение живёт рядом со статусом, а
        // не вместо него — студент может быть и «не вошёл», и отмеченным.
        if (! empty($filters['marked_without_entry'])) {
            $rows = $rows->filter(fn (array $row): bool => (bool) ($row['marked_present_without_entry'] ?? false));
        }

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
            'marked_present_without_entry' => $rows->where('marked_present_without_entry', true)->count(),
            'visiting' => $rows->where('is_visiting', true)->count(),
        ];
    }

    /**
     * График рабочего дня сотрудника по его человеку. Одним запросом на весь
     * отчёт: спрашивать его на каждого преподавателя значило бы вернуть ту же
     * беду, что уже ловили на списке эвакуации.
     *
     * @return \Illuminate\Support\Collection<int, array{code: ?string, employment: ?string}>
     */
    private function workSchedules(): Collection
    {
        if ($this->scheduleCache !== null) {
            return $this->scheduleCache;
        }

        return $this->scheduleCache = Employee::query()
            ->whereNotNull('person_id')
            ->get(['person_id', 'work_schedule_code', 'employment_type'])
            ->keyBy('person_id')
            ->map(fn (Employee $employee): array => [
                'code' => $employee->work_schedule_code,
                'employment' => $employee->employment_type,
            ]);
    }

    /**
     * Приходящий преподаватель: свободный график или внешнее совместительство.
     * Он появляется к своему занятию и уходит после него, и мерить его днём с
     * девяти до шести неверно.
     *
     * @param array{code: ?string, employment: ?string}|null $schedule
     */
    private function isVisiting(?array $schedule): bool
    {
        if ($schedule === null) {
            return false;
        }

        return ($schedule['code'] ?? null) === 'flexible'
            || in_array($schedule['employment'] ?? null, ['external_part_time', 'contract'], true);
    }

    private function isVisitingPerson(string $type, Student|Teacher $person): bool
    {
        if ($type !== DigitalIdentity::ENTITY_TEACHER || ! $person->person_id) {
            return false;
        }

        return $this->isVisiting($this->workSchedules()[$person->person_id] ?? null);
    }

    /** @param array{code: ?string, employment: ?string}|null $schedule */
    private function workScheduleLabel(?array $schedule): ?string
    {
        return match ($schedule['code'] ?? null) {
            'weekday_0900_1800' => 'Будни 9:00–18:00',
            'weekday_0900_1700' => 'Будни 9:00–17:00',
            'shift_2_2_0800_2000' => 'Сменный 2/2, 8:00–20:00',
            'flexible' => 'Свободный график',
            default => $this->isVisiting($schedule) ? 'Приходящий' : null,
        };
    }

    /**
     * Студенты, отмеченные преподавателем на занятии за период.
     *
     * Одним запросом на весь отчёт, а не по человеку. Прогулявший занятие сюда
     * не попадает: отметка «отсутствовал» расхождением с проходной не является.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function studentsMarkedPresent(CarbonImmutable $dateFrom, CarbonImmutable $dateTo): Collection
    {
        return JournalAttendance::query()
            ->join('journal_lessons', 'journal_lessons.id', '=', 'journal_attendance.journal_lesson_id')
            ->whereIn('journal_attendance.status', [JournalAttendance::STATUS_PRESENT, JournalAttendance::STATUS_LATE])
            ->whereDate('journal_lessons.lesson_date', '>=', $dateFrom->toDateString())
            ->whereDate('journal_lessons.lesson_date', '<=', $dateTo->toDateString())
            ->distinct()
            ->pluck('journal_attendance.student_id')
            ->flip();
    }


    /** @param array<string, mixed> $filters */
    public function history(array $filters = []): array
    {
        $type = $this->normalizeType($filters['type'] ?? DigitalIdentity::ENTITY_STUDENT);
        [$dateFrom, $dateTo] = $this->dateRange($filters);
        $people = $this->historyPeople($type, $filters);
        $rows = $people->map(function (Student|Teacher $person) use ($type, $dateFrom, $dateTo): array {
            $days = $this->personDaysPayload($type, (int) $person->id, $dateFrom, $dateTo, $person);
            return $this->historyRow($type, $person, collect($days));
        });

        $rows = $this->filterHistoryRows($rows, $filters);

        return [
            'date_from' => $dateFrom->toDateString(),
            'date_to' => $dateTo->toDateString(),
            'type' => $type,
            'data' => $rows->values()->all(),
            'summary' => [
                'total' => $rows->count(),
                'present_days' => $rows->sum('present_days'),
                'absent_days' => $rows->sum('absent_days'),
                'late_count' => $rows->sum('late_count'),
                'early_leave_count' => $rows->sum('early_leave_count'),
                'minutes_inside' => $rows->sum('minutes_inside'),
                'open_sessions' => $rows->where('has_open_session', true)->count(),
            ],
        ];
    }

    public function personSummary(string $type, int $id, array $filters = []): array
    {
        $type = $this->normalizeType($type);
        [$dateFrom, $dateTo] = $this->dateRange($filters);
        $person = $this->findPerson($type, $id);
        $days = collect($this->personDaysPayload($type, $id, $dateFrom, $dateTo, $person));

        return [
            'date_from' => $dateFrom->toDateString(),
            'date_to' => $dateTo->toDateString(),
            'type' => $type,
            'person' => $this->personPayload($type, $person),
            'summary' => $this->historyRow($type, $person, $days),
        ];
    }

    public function personDays(string $type, int $id, array $filters = []): array
    {
        $type = $this->normalizeType($type);
        [$dateFrom, $dateTo] = $this->dateRange($filters);
        $person = $this->findPerson($type, $id);

        return [
            'date_from' => $dateFrom->toDateString(),
            'date_to' => $dateTo->toDateString(),
            'type' => $type,
            'person' => $this->personPayload($type, $person),
            'data' => $this->personDaysPayload($type, $id, $dateFrom, $dateTo, $person),
        ];
    }

    /** @param array<string, mixed> $filters */
    public function historyCsvRows(array $filters = []): array
    {
        $rows = $this->history($filters)['data'];
        return collect($rows)->map(fn (array $row) => [
            'ФИО' => $row['full_name'],
            'Тип' => $row['entity_type'] === DigitalIdentity::ENTITY_TEACHER ? 'Преподаватель' : 'Студент',
            'Дней по расписанию' => $row['scheduled_days'],
            'Присутствовал' => $row['present_days'],
            'Отсутствовал' => $row['absent_days'],
            'Опозданий' => $row['late_count'],
            'Минут опоздания' => $row['late_minutes_total'],
            'Ранних уходов' => $row['early_leave_count'],
            'Минут раннего ухода' => $row['early_leave_minutes_total'],
            'Время внутри, минут' => $row['minutes_inside'],
            'Среднее в день, минут' => $row['average_minutes_per_present_day'],
            'Незакрытая сессия' => $row['has_open_session'] ? 'Да' : 'Нет',
        ])->all();
    }

    private function historyPeople(string $type, array $filters): Collection
    {
        if ($type === DigitalIdentity::ENTITY_TEACHER) {
            return Teacher::query()
                ->when($filters['person_id'] ?? null, fn (Builder $query, mixed $id) => $query->whereKey($id))
                ->when($filters['teacher_id'] ?? null, fn (Builder $query, mixed $id) => $query->whereKey($id))
                ->orderBy('last_name')->orderBy('first_name')->get();
        }

        return Student::query()
            ->with('group')
            ->where('status', 'active')
            ->when($filters['person_id'] ?? null, fn (Builder $query, mixed $id) => $query->whereKey($id))
            ->when($filters['group_id'] ?? null, fn (Builder $query, mixed $groupId) => $query->where('group_id', $groupId))
            ->orderBy('last_name')->orderBy('first_name')->get();
    }

    private function personDaysPayload(string $type, int $id, CarbonImmutable $dateFrom, CarbonImmutable $dateTo, Student|Teacher $person): array
    {
        $lessons = $this->personLessons($type, $person, $dateFrom, $dateTo)->groupBy(fn (ScheduleLesson $lesson) => $lesson->lesson_date?->toDateString());
        $events = $this->personEvents($type, $id, $dateFrom, $dateTo);
        $days = [];
        $cursor = $dateFrom;

        while ($cursor->lessThanOrEqualTo($dateTo)) {
            $dayLessons = $lessons->get($cursor->toDateString(), collect());
            $dayEvents = $this->eventsForSessionDay($events, $cursor);
            $days[] = $this->personDayPayload($type, $person, $cursor, $dayLessons, $dayEvents);
            $cursor = $cursor->addDay();
        }

        return $days;
    }

    private function personDayPayload(string $type, Student|Teacher $person, CarbonImmutable $date, Collection $lessons, Collection $events): array
    {
        $sessionsPayload = $this->sessionPairs($events, $date);
        $sessions = collect($sessionsPayload['sessions']);
        $firstEntry = $sessions->pluck('in')->filter()->sort()->first();
        $lastExit = $sessions->pluck('out')->filter()->sort()->last();
        $plannedStart = $this->plannedStart($lessons, $date);
        $plannedEnd = $this->plannedEnd($lessons, $date);
        $lateMinutes = $this->lateMinutes($firstEntry ? CarbonImmutable::parse($firstEntry) : null, $plannedStart) ?? 0;
        $earlyLeaveMinutes = $this->earlyLeaveMinutes($lastExit ? CarbonImmutable::parse($lastExit) : null, $plannedEnd);
        $scheduled = $lessons->isNotEmpty();
        $present = $firstEntry !== null;
        $absent = $scheduled && ! $present;
        $status = $this->historyDayStatus($scheduled, $present, $lateMinutes, $earlyLeaveMinutes, (bool) $sessionsPayload['has_open_session']);

        return [
            'date' => $date->toDateString(),
            'entity_type' => $type,
            'entity_id' => $person->id,
            'full_name' => $this->fullName($person),
            'scheduled' => $scheduled,
            'present' => $present,
            'absent' => $absent,
            'status' => $status['code'],
            'status_label' => $status['label'],
            'status_tone' => $status['tone'],
            'planned_start' => $plannedStart?->toISOString(),
            'planned_end' => $plannedEnd?->toISOString(),
            'first_entry' => $firstEntry,
            'last_exit' => $lastExit,
            'entries_count' => $sessionsPayload['entries_count'],
            'exits_count' => $sessionsPayload['exits_count'],
            'unmatched_exits_count' => $sessionsPayload['unmatched_exits_count'],
            'minutes_inside' => $sessions->sum('minutes_inside'),
            'late_minutes' => $lateMinutes,
            'is_late' => $scheduled && $lateMinutes > 0,
            'early_leave_minutes' => $earlyLeaveMinutes,
            // Приходящему преподавателю ранний уход не ставится: он и приходит
            // ради своего занятия. Мерить его концом последней пары в колледже
            // значило бы записывать в нарушители за то, ради чего его позвали.
            'is_early_leave' => $scheduled && $earlyLeaveMinutes > 0 && ! $this->isVisitingPerson($type, $person),
            'has_open_session' => (bool) $sessionsPayload['has_open_session'],
            'sessions' => $sessionsPayload['sessions'],
            'lessons' => $lessons->map(fn (ScheduleLesson $lesson) => $this->lessonPayload($lesson, $date))->values()->all(),
            'events' => $events->map(fn (AccessEvent $event) => [
                'id' => $event->id,
                'direction' => $event->direction,
                'event_time' => $this->formatDateTime($event->event_time),
            ])->values()->all(),
        ];
    }

    private function historyRow(string $type, Student|Teacher $person, Collection $days): array
    {
        $scheduledDays = $days->where('scheduled', true)->count();
        $presentDays = $days->where('present', true)->count();
        $minutesInside = $days->sum('minutes_inside');
        $lateCount = $days->where('is_late', true)->count();
        $earlyLeaveCount = $days->where('is_early_leave', true)->count();
        $status = $this->historyAggregateStatus($days);

        return [
            'id' => $type.'-'.$person->id,
            'entity_type' => $type,
            'entity_id' => $person->id,
            'full_name' => $this->fullName($person),
            'photo_url' => $this->photoUrl($person->photo_path),
            'group_id' => $type === DigitalIdentity::ENTITY_STUDENT ? $person->group_id : null,
            'group' => $type === DigitalIdentity::ENTITY_STUDENT ? $person->group?->name : null,
            'department' => $type === DigitalIdentity::ENTITY_TEACHER ? $person->department : null,
            'status' => $status['code'],
            'status_label' => $status['label'],
            'status_tone' => $status['tone'],
            'scheduled_days' => $scheduledDays,
            'present_days' => $presentDays,
            'absent_days' => $days->where('absent', true)->count(),
            'days_without_schedule' => $days->where('scheduled', false)->count(),
            'entries_count' => $days->sum('entries_count'),
            'exits_count' => $days->sum('exits_count'),
            'late_count' => $lateCount,
            'late_minutes_total' => $days->sum('late_minutes'),
            'early_leave_count' => $earlyLeaveCount,
            'early_leave_minutes_total' => $days->sum('early_leave_minutes'),
            'minutes_inside' => $minutesInside,
            'average_minutes_per_present_day' => $presentDays > 0 ? (int) round($minutesInside / $presentDays) : 0,
            'first_entry' => $days->pluck('first_entry')->filter()->sort()->first(),
            'last_exit' => $days->pluck('last_exit')->filter()->sort()->last(),
            'has_open_session' => $days->where('has_open_session', true)->isNotEmpty(),
        ];
    }

    private function sessionPairs(Collection $events, CarbonImmutable $date): array
    {
        $sessions = [];
        $openIn = null;
        $entries = 0;
        $exits = 0;
        $unmatchedExits = 0;

        foreach ($events->sortBy('event_time') as $event) {
            if ($event->direction === AccessEvent::DIRECTION_IN) {
                $eventTime = CarbonImmutable::instance($event->event_time);
                if ($eventTime->toDateString() !== $date->toDateString()) {
                    continue;
                }
                if ($openIn === null) {
                    $openIn = $eventTime;
                    $entries++;
                }
                continue;
            }

            if ($event->direction === AccessEvent::DIRECTION_OUT) {
                $eventTime = CarbonImmutable::instance($event->event_time);
                $exits++;
                if ($openIn === null) {
                    $unmatchedExits++;
                    continue;
                }
                $sessions[] = [
                    'in' => $openIn->toISOString(),
                    'out' => $eventTime->toISOString(),
                    'minutes_inside' => max(0, (int) $openIn->diffInMinutes($eventTime)),
                    'open' => false,
                ];
                $openIn = null;
            }
        }

        if ($openIn !== null) {
            $sessions[] = [
                'in' => $openIn->toISOString(),
                'out' => null,
                'minutes_inside' => 0,
                'open' => true,
            ];
        }

        return [
            'sessions' => $sessions,
            'entries_count' => $entries,
            'exits_count' => $exits,
            'unmatched_exits_count' => $unmatchedExits,
            'has_open_session' => $openIn !== null,
        ];
    }

    private function eventsForSessionDay(Collection $events, CarbonImmutable $date): Collection
    {
        $maxHours = max(1, (int) SettingService::value('attendance', 'max_open_session_hours', 16));
        $from = $date->startOfDay();
        $to = $date->endOfDay()->addHours($maxHours);

        return $events->filter(fn (AccessEvent $event) => CarbonImmutable::instance($event->event_time)->betweenIncluded($from, $to))->values();
    }

    private function personEvents(string $type, int $id, CarbonImmutable $dateFrom, CarbonImmutable $dateTo): Collection
    {
        $maxHours = max(1, (int) SettingService::value('attendance', 'max_open_session_hours', 16));
        return AccessEvent::query()
            ->where('entity_type', $type)
            ->where('entity_id', $id)
            ->where('result', AccessEvent::RESULT_ALLOWED)
            ->whereBetween('event_time', [$dateFrom->startOfDay(), $dateTo->endOfDay()->addHours($maxHours)])
            ->orderBy('event_time')
            ->get();
    }

    private function personLessons(string $type, Student|Teacher $person, CarbonImmutable $dateFrom, CarbonImmutable $dateTo): Collection
    {
        return ScheduleLesson::query()
            ->with(['group', 'teacher', 'subject', 'classroom'])
            ->whereDate('lesson_date', '>=', $dateFrom->toDateString())
            ->whereDate('lesson_date', '<=', $dateTo->toDateString())
            ->when($type === DigitalIdentity::ENTITY_TEACHER, fn (Builder $query) => $query->where('teacher_id', $person->id))
            ->when($type === DigitalIdentity::ENTITY_STUDENT, fn (Builder $query) => $query->where('group_id', $person->group_id))
            ->orderBy('lesson_date')->orderBy('starts_at')->get();
    }

    private function plannedStart(Collection $lessons, CarbonImmutable $date): ?CarbonImmutable
    {
        $lesson = $lessons->sortBy(fn (ScheduleLesson $lesson) => $this->lessonStart($lesson, $date)?->timestamp ?? PHP_INT_MAX)->first();
        return $lesson ? $this->lessonStart($lesson, $date) : null;
    }

    private function plannedEnd(Collection $lessons, CarbonImmutable $date): ?CarbonImmutable
    {
        $lesson = $lessons->sortByDesc(fn (ScheduleLesson $lesson) => $this->lessonEnd($lesson, $date)?->timestamp ?? 0)->first();
        return $lesson ? $this->lessonEnd($lesson, $date) : null;
    }

    private function lessonEnd(ScheduleLesson $lesson, CarbonImmutable $date): ?CarbonImmutable
    {
        $time = $this->formatTime($lesson->ends_at);
        $lessonDate = $lesson->lesson_date?->toDateString() ?: $date->toDateString();
        return $time ? CarbonImmutable::parse($lessonDate.' '.$time) : null;
    }

    private function earlyLeaveMinutes(?CarbonImmutable $lastExit, ?CarbonImmutable $plannedEnd): int
    {
        if ($lastExit === null || $plannedEnd === null) {
            return 0;
        }
        $diff = $lastExit->lessThan($plannedEnd) ? (int) $lastExit->diffInMinutes($plannedEnd) : 0;
        $threshold = (int) SettingService::value('attendance', 'early_leave_threshold_minutes', 10);
        return $diff >= $threshold ? $diff : 0;
    }

    private function historyDayStatus(bool $scheduled, bool $present, int $lateMinutes, int $earlyLeaveMinutes, bool $open): array
    {
        if (! $scheduled && ! $present) {
            return ['code' => 'no_schedule', 'label' => 'Нет расписания', 'tone' => 'neutral'];
        }
        if ($scheduled && ! $present) {
            return ['code' => 'absent', 'label' => 'Отсутствовал', 'tone' => 'danger'];
        }
        if ($open) {
            return ['code' => 'open_session', 'label' => 'Незакрытый вход', 'tone' => 'warning'];
        }
        if ($lateMinutes > 0) {
            return ['code' => 'late', 'label' => 'Опоздал', 'tone' => 'warning'];
        }
        if ($earlyLeaveMinutes > 0) {
            return ['code' => 'early_leave', 'label' => 'Ранний уход', 'tone' => 'warning'];
        }
        return ['code' => 'present', 'label' => $present ? 'Присутствовал' : 'Нет данных', 'tone' => 'success'];
    }

    private function historyAggregateStatus(Collection $days): array
    {
        if ($days->where('has_open_session', true)->isNotEmpty()) {
            return ['code' => 'open_session', 'label' => 'Есть незакрытый вход', 'tone' => 'warning'];
        }
        if ($days->where('absent', true)->isNotEmpty()) {
            return ['code' => 'absent', 'label' => 'Есть отсутствия', 'tone' => 'danger'];
        }
        if ($days->where('is_late', true)->isNotEmpty()) {
            return ['code' => 'late', 'label' => 'Есть опоздания', 'tone' => 'warning'];
        }
        if ($days->where('is_early_leave', true)->isNotEmpty()) {
            return ['code' => 'early_leave', 'label' => 'Есть ранние уходы', 'tone' => 'warning'];
        }
        return ['code' => 'present', 'label' => 'Без отклонений', 'tone' => 'success'];
    }

    private function filterHistoryRows(Collection $rows, array $filters): Collection
    {
        $status = (string) ($filters['status'] ?? '');
        if ($status === '') {
            return $rows;
        }
        $statuses = match ($status) {
            'absent' => ['absent'],
            'late' => ['late'],
            'early_leave' => ['early_leave'],
            'open_session' => ['open_session'],
            default => [$status],
        };
        return $rows->filter(fn (array $row) => in_array($row['status'], $statuses, true));
    }

    private function normalizeType(mixed $type): string
    {
        return $type === DigitalIdentity::ENTITY_TEACHER ? DigitalIdentity::ENTITY_TEACHER : DigitalIdentity::ENTITY_STUDENT;
    }

    private function findPerson(string $type, int $id): Student|Teacher
    {
        return $type === DigitalIdentity::ENTITY_TEACHER
            ? Teacher::query()->findOrFail($id)
            : Student::query()->with('group')->findOrFail($id);
    }

    private function personPayload(string $type, Student|Teacher $person): array
    {
        return [
            'id' => $person->id,
            'type' => $type,
            'full_name' => $this->fullName($person),
            'photo_url' => $this->photoUrl($person->photo_path),
            'group' => $type === DigitalIdentity::ENTITY_STUDENT ? $person->group?->name : null,
            'department' => $type === DigitalIdentity::ENTITY_TEACHER ? $person->department : null,
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

    /** @param Collection<int, array<string, mixed>> $rows */
    private function markedWithoutEntryRows(Collection $rows): array
    {
        $flagged = $rows->filter(fn (array $row): bool => (bool) ($row['marked_present_without_entry'] ?? false));
        $to = '/attendance?type=students&marked_without_entry=1';

        return [
            'count' => $flagged->count(),
            'items' => $flagged
                ->take(5)
                ->map(fn (array $row) => [
                    'id' => $row['id'],
                    'name' => $row['full_name'],
                    'status' => 'Отмечен на занятии, входа нет',
                    'group' => $row['group'] ?? null,
                    'to' => $to,
                ])
                ->values()
                ->all(),
            'to' => $to,
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
