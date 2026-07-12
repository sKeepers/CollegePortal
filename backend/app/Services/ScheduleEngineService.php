<?php

namespace App\Services;

use App\Models\Classroom;
use App\Models\Employee;
use App\Models\Group;
use App\Models\ReferenceItem;
use App\Models\ScheduleEntry;
use App\Models\ScheduleLesson;
use App\Models\ScheduleTemplate;
use App\Models\Teacher;
use App\Models\TeachingLoadItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleEngineService
{
    public function preview(array $payload, ?int $ignoreEntryId = null): array
    {
        $entry = $this->normalize($payload);
        $conflicts = $this->validateEntry($entry, $ignoreEntryId);
        $blocking = collect($conflicts)->where('level', 'blocking')->values()->all();
        $warnings = collect($conflicts)->where('level', 'warning')->values()->all();

        return [
            'entry' => $entry,
            'can_apply' => $blocking === [],
            'blocking_count' => count($blocking),
            'warning_count' => count($warnings),
            'conflicts' => $conflicts,
            'recommendation' => $blocking === []
                ? 'Запись можно применить. Предупреждения следует проверить вручную.'
                : 'Исправьте блокирующие конфликты перед сохранением.',
        ];
    }

    public function apply(array $payload, ?User $user = null): array
    {
        return DB::transaction(function () use ($payload, $user): array {
            $preview = $this->preview($payload);
            if (! $preview['can_apply']) {
                throw ValidationException::withMessages(['schedule' => 'Есть блокирующие конфликты расписания.']);
            }

            $entryData = $preview['entry'];
            $entry = ScheduleEntry::create([
                ...$entryData,
                'status' => $entryData['status'] ?: 'scheduled',
                'source' => $entryData['source'] ?: 'schedule_engine',
                'created_by' => $user?->id,
                'updated_by' => $user?->id,
            ]);
            $this->syncLegacyLesson($entry);
            AuditLogService::log('Schedule', 'schedule_entry_created', $entry, null, $entry->getAttributes(), user: $user);

            return [
                ...$preview,
                'applied' => true,
                'entry' => $entry->fresh($this->relations()),
            ];
        });
    }

    public function update(ScheduleEntry $entry, array $payload, ?User $user = null, string $action = 'schedule_entry_updated'): ScheduleEntry
    {
        return DB::transaction(function () use ($entry, $payload, $user, $action): ScheduleEntry {
            $old = $entry->getAttributes();
            $preview = $this->preview([...$this->entryArray($entry), ...$payload], $entry->id);
            if (! $preview['can_apply']) {
                throw ValidationException::withMessages(['schedule' => 'Есть блокирующие конфликты расписания.']);
            }

            $entry->update([...$preview['entry'], 'updated_by' => $user?->id]);
            $this->syncLegacyLesson($entry->fresh());
            AuditLogService::log('Schedule', $action, $entry, $old, $entry->getAttributes(), user: $user);

            return $entry->fresh($this->relations());
        });
    }

    public function replaceTeacher(ScheduleEntry $entry, int $teacherId, ?User $user = null): ScheduleEntry
    {
        return $this->update($entry, ['teacher_id' => $teacherId, 'is_replacement' => true, 'replaced_entry_id' => $entry->replaced_entry_id ?: $entry->id], $user, 'schedule_teacher_replaced');
    }

    public function replaceClassroom(ScheduleEntry $entry, ?int $classroomId, ?User $user = null): ScheduleEntry
    {
        return $this->update($entry, ['classroom_id' => $classroomId, 'is_replacement' => true, 'replaced_entry_id' => $entry->replaced_entry_id ?: $entry->id], $user, 'schedule_classroom_replaced');
    }

    public function move(ScheduleEntry $entry, array $payload, ?User $user = null): ScheduleEntry
    {
        return $this->update($entry, [...$payload, 'is_replacement' => true, 'replaced_entry_id' => $entry->replaced_entry_id ?: $entry->id], $user, 'schedule_entry_moved');
    }

    public function cancel(ScheduleEntry $entry, ?User $user = null): ScheduleEntry
    {
        $old = $entry->getAttributes();
        $entry->update(['status' => 'canceled', 'updated_by' => $user?->id]);
        $entry->legacyLesson?->delete();
        AuditLogService::log('Schedule', 'schedule_entry_canceled', $entry, $old, $entry->getAttributes(), user: $user);

        return $entry->fresh($this->relations());
    }

    public function restore(ScheduleEntry $entry, ?User $user = null): ScheduleEntry
    {
        $old = $entry->getAttributes();
        $entry->update(['status' => 'scheduled', 'updated_by' => $user?->id]);
        $this->syncLegacyLesson($entry->fresh());
        AuditLogService::log('Schedule', 'schedule_entry_restored', $entry, $old, $entry->getAttributes(), user: $user);

        return $entry->fresh($this->relations());
    }

    public function conflicts(array $filters = []): array
    {
        $query = ScheduleEntry::query()->with($this->relations())->where('status', '!=', 'canceled');
        $this->applyFilters($query, $filters);

        return $query->get()
            ->flatMap(fn (ScheduleEntry $entry) => $this->validateEntry($this->entryArray($entry), $entry->id))
            ->values()
            ->all();
    }

    public function coverage(array $filters = []): array
    {
        $query = TeachingLoadItem::query()->with(['subject', 'group', 'teacher']);
        if (! empty($filters['group_id'])) { $query->where('group_id', $filters['group_id']); }
        if (! empty($filters['teacher_id'])) { $query->where('teacher_id', $filters['teacher_id']); }
        if (! empty($filters['semester'])) { $query->where('semester', $filters['semester']); }

        return $query->get()->map(function (TeachingLoadItem $item): array {
            $scheduled = $this->scheduledHoursForItem($item->id);
            $planned = (int) ($item->planned_hours ?: $item->hours_total);
            $remaining = max(0, $planned - $scheduled);
            $over = max(0, $scheduled - $planned);

            return [
                'teaching_load_item_id' => $item->id,
                'subject' => $item->subject?->name,
                'group' => $item->group?->name,
                'teacher' => $item->teacher ? trim("{$item->teacher->last_name} {$item->teacher->first_name} {$item->teacher->middle_name}") : 'Не назначен',
                'semester' => $item->semester,
                'planned_hours' => $planned,
                'scheduled_hours' => $scheduled,
                'remaining_hours' => $remaining,
                'over_scheduled_hours' => $over,
                'status' => match (true) {
                    $scheduled <= 0 => 'not_scheduled',
                    $over > 0 => 'over_scheduled',
                    $remaining > 0 => 'partially_scheduled',
                    default => 'scheduled',
                },
            ];
        })->values()->all();
    }

    public function query(array $filters = []): Builder
    {
        $query = ScheduleEntry::query()->with($this->relations())->orderBy('date')->orderBy('starts_at');
        $this->applyFilters($query, $filters);
        return $query;
    }


    public function createTemplate(array $payload, ?User $user = null): ScheduleTemplate
    {
        return DB::transaction(function () use ($payload, $user): ScheduleTemplate {
            $entries = $payload['entries'] ?? [];
            unset($payload['entries']);
            $template = ScheduleTemplate::create([
                ...$payload,
                'status' => $payload['status'] ?? 'draft',
                'created_by' => $user?->id,
                'updated_by' => $user?->id,
            ]);

            foreach ($entries as $entry) {
                $template->entries()->create($entry);
            }

            AuditLogService::log('Schedule', 'schedule_template_created', $template, null, $template->load('entries')->toArray(), user: $user);

            return $template->fresh(['group', 'entries.subject', 'entries.teacher', 'entries.classroom']);
        });
    }

    public function applyTemplatePreview(ScheduleTemplate $template, string $dateFrom, string $dateTo): array
    {
        return $this->applyTemplate($template, $dateFrom, $dateTo, apply: false);
    }

    public function applyTemplateConfirm(ScheduleTemplate $template, string $dateFrom, string $dateTo, ?User $user = null): array
    {
        return DB::transaction(fn () => $this->applyTemplate($template, $dateFrom, $dateTo, apply: true, user: $user));
    }

    private function applyTemplate(ScheduleTemplate $template, string $dateFrom, string $dateTo, bool $apply, ?User $user = null): array
    {
        $template->load(['entries', 'group']);
        $start = CarbonImmutable::parse($dateFrom)->startOfDay();
        $end = CarbonImmutable::parse($dateTo)->startOfDay();
        $report = [
            'template_id' => $template->id,
            'template' => $template->name,
            'date_from' => $start->toDateString(),
            'date_to' => $end->toDateString(),
            'found' => 0,
            'will_create' => 0,
            'blocking_count' => 0,
            'warning_count' => 0,
            'items' => [],
        ];

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            foreach ($template->entries as $templateEntry) {
                if ((int) $templateEntry->day_of_week !== $date->dayOfWeekIso) {
                    continue;
                }

                $entryPayload = [
                    'academic_year' => $template->academic_year,
                    'semester' => $template->semester,
                    'date' => $date->toDateString(),
                    'day_of_week' => $date->dayOfWeekIso,
                    'week_type' => $templateEntry->week_type ?: $template->week_type,
                    'lesson_number' => $templateEntry->lesson_number,
                    'starts_at' => $this->timeString($templateEntry->starts_at),
                    'ends_at' => $this->timeString($templateEntry->ends_at),
                    'group_id' => $template->group_id,
                    'subject_id' => $templateEntry->subject_id,
                    'teacher_id' => $templateEntry->teacher_id,
                    'classroom_id' => $templateEntry->classroom_id,
                    'teaching_load_item_id' => $templateEntry->teaching_load_item_id,
                    'lesson_type_id' => $templateEntry->lesson_type_id,
                    'status' => 'scheduled',
                    'source' => 'schedule_template',
                    'comment' => $templateEntry->comment,
                ];
                $preview = $this->preview($entryPayload);
                $report['found']++;
                $report['will_create'] += $preview['can_apply'] ? 1 : 0;
                $report['blocking_count'] += $preview['blocking_count'];
                $report['warning_count'] += $preview['warning_count'];
                $report['items'][] = [
                    'date' => $date->toDateString(),
                    'lesson_number' => $templateEntry->lesson_number,
                    'subject_id' => $templateEntry->subject_id,
                    'can_apply' => $preview['can_apply'],
                    'conflicts' => $preview['conflicts'],
                ];

                if ($apply && $preview['can_apply']) {
                    $this->apply($entryPayload, $user);
                }
            }
        }

        if ($apply) {
            AuditLogService::log('Schedule', 'schedule_template_applied', $template, null, $report, user: $user);
        }

        return $report;
    }

    public function relations(): array
    {
        return ['group', 'teacher.employee.statusPeriods', 'teacher.employee.primaryDepartment', 'teacher.employee.primaryPosition', 'subject', 'classroom', 'teachingLoadItem', 'lessonType', 'legacyLesson'];
    }

    private function teacherHrWarning(?Teacher $teacher, array $entry): ?array
    {
        $employee = $teacher?->employee;
        if (! $employee) {
            return null;
        }
        $status = $employee->statusOn($entry['date'] ?? now()->toDateString());
        if (! in_array($status, Employee::UNAVAILABLE_STATUSES, true)) {
            return null;
        }

        return $this->conflict('teacher_hr_unavailable', 'warning', 'Преподаватель недоступен по кадровым данным', $entry);
    }

    private function normalize(array $payload): array
    {
        $date = $payload['date'] ?? $payload['lesson_date'] ?? null;
        $dayOfWeek = $payload['day_of_week'] ?? null;
        if ($date && ! $dayOfWeek) {
            $dayOfWeek = CarbonImmutable::parse($date)->dayOfWeekIso;
        }

        return [
            'academic_year' => (string) ($payload['academic_year'] ?? $this->academicYearForDate($date)),
            'semester' => (int) ($payload['semester'] ?? $this->semesterForDate($date)),
            'date' => $date,
            'day_of_week' => $dayOfWeek ? (int) $dayOfWeek : null,
            'week_type' => $payload['week_type'] ?? null,
            'lesson_number' => (int) ($payload['lesson_number'] ?? 1),
            'starts_at' => substr((string) ($payload['starts_at'] ?? ''), 0, 5),
            'ends_at' => substr((string) ($payload['ends_at'] ?? ''), 0, 5),
            'group_id' => (int) ($payload['group_id'] ?? 0),
            'subject_id' => (int) ($payload['subject_id'] ?? 0),
            'teacher_id' => (int) ($payload['teacher_id'] ?? 0),
            'classroom_id' => isset($payload['classroom_id']) && $payload['classroom_id'] !== '' ? (int) $payload['classroom_id'] : null,
            'teaching_load_item_id' => isset($payload['teaching_load_item_id']) && $payload['teaching_load_item_id'] !== '' ? (int) $payload['teaching_load_item_id'] : null,
            'lesson_type_id' => isset($payload['lesson_type_id']) && $payload['lesson_type_id'] !== '' ? (int) $payload['lesson_type_id'] : null,
            'status' => $payload['status'] ?? 'scheduled',
            'source' => $payload['source'] ?? 'schedule_engine',
            'is_replacement' => (bool) ($payload['is_replacement'] ?? false),
            'replaced_entry_id' => isset($payload['replaced_entry_id']) && $payload['replaced_entry_id'] !== '' ? (int) $payload['replaced_entry_id'] : null,
            'comment' => $payload['comment'] ?? $payload['topic'] ?? null,
        ];
    }

    private function validateEntry(array $entry, ?int $ignoreEntryId = null): array
    {
        $conflicts = [];
        if (! $entry['date'] && ! $entry['day_of_week']) { $conflicts[] = $this->conflict('time', 'blocking', 'Не указана дата или день недели.', $entry); }
        if (! $entry['starts_at'] || ! $entry['ends_at'] || $entry['starts_at'] >= $entry['ends_at']) { $conflicts[] = $this->conflict('time', 'blocking', 'Время окончания должно быть позже начала.', $entry); }

        $group = Group::query()->withCount('students')->find($entry['group_id']);
        $classroom = $entry['classroom_id'] ? Classroom::find($entry['classroom_id']) : null;
        $teacher = Teacher::query()->with('employee.statusPeriods')->find($entry['teacher_id']);
        if ($teacher && ($hrWarning = $this->teacherHrWarning($teacher, $entry))) {
            $conflicts[] = $hrWarning;
        }
        $loadItem = $this->resolveLoadItem($entry);

        if (! $loadItem) {
            $conflicts[] = $this->conflict('teaching_load', 'blocking', 'Дисциплина отсутствует в нагрузке выбранной группы.', $entry);
        } else {
            if ((int) $loadItem->group_id !== (int) $entry['group_id'] || (int) $loadItem->subject_id !== (int) $entry['subject_id']) {
                $conflicts[] = $this->conflict('teaching_load', 'blocking', 'Строка нагрузки не соответствует группе или дисциплине.', $entry);
            }
            if (! $loadItem->teacher_id) {
                $conflicts[] = $this->conflict('teacher_assignment', 'warning', 'В строке нагрузки преподаватель еще не назначен.', $entry);
            } elseif ((int) $loadItem->teacher_id !== (int) $entry['teacher_id']) {
                $conflicts[] = $this->conflict('teacher_assignment', 'blocking', 'Преподаватель не соответствует назначению в нагрузке.', $entry);
            }
            $planned = (int) ($loadItem->planned_hours ?: $loadItem->hours_total);
            $scheduled = $this->scheduledHoursForItem($loadItem->id, $ignoreEntryId) + $this->academicHours($entry['starts_at'], $entry['ends_at']);
            if ($scheduled > $planned) {
                $conflicts[] = $this->conflict('hours_over_plan', 'warning', "Запланировано {$scheduled} ч. при плане {$planned} ч.", $entry);
            }
        }

        if ($group && $classroom && $classroom->capacity && $group->students_count > $classroom->capacity) {
            $conflicts[] = $this->conflict('classroom_capacity', 'warning', "Вместимость аудитории {$classroom->capacity}, студентов в группе {$group->students_count}.", $entry);
        }

        foreach (['group_id' => 'Группа уже занята.', 'teacher_id' => 'Преподаватель уже занят.', 'classroom_id' => 'Аудитория уже занята.'] as $column => $message) {
            if ($entry[$column] && $this->hasScheduleEntryConflict($column, (int) $entry[$column], $entry, $ignoreEntryId)) {
                $conflicts[] = $this->conflict(str_replace('_id', '', $column).'_busy', 'blocking', $message, $entry);
            }
        }

        if ($this->hasLegacyLessonConflict($entry)) {
            $conflicts[] = $this->conflict('legacy_duplicate', 'blocking', 'Есть пересечение с существующей записью schedule_lessons.', $entry);
        }

        if ($this->hasExactDuplicate($entry, $ignoreEntryId)) {
            $conflicts[] = $this->conflict('duplicate', 'blocking', 'Такая запись расписания уже существует.', $entry);
        }

        return $conflicts;
    }

    private function resolveLoadItem(array $entry): ?TeachingLoadItem
    {
        if ($entry['teaching_load_item_id']) {
            return TeachingLoadItem::query()->find($entry['teaching_load_item_id']);
        }

        return TeachingLoadItem::query()
            ->where('group_id', $entry['group_id'])
            ->where('subject_id', $entry['subject_id'])
            ->when($entry['semester'], fn (Builder $query, int $semester) => $query->where('semester', $semester))
            ->orderByRaw('case when teacher_id = ? then 0 else 1 end', [$entry['teacher_id']])
            ->first();
    }

    private function syncLegacyLesson(ScheduleEntry $entry): void
    {
        if (! $entry->date || $entry->status === 'canceled') {
            return;
        }

        ScheduleLesson::query()->updateOrCreate(
            ['schedule_entry_id' => $entry->id],
            [
                'group_id' => $entry->group_id,
                'teacher_id' => $entry->teacher_id,
                'subject_id' => $entry->subject_id,
                'classroom_id' => $entry->classroom_id,
                'lesson_date' => $entry->date->toDateString(),
                'starts_at' => $this->timeString($entry->starts_at),
                'ends_at' => $this->timeString($entry->ends_at),
                'lesson_type' => $this->lessonTypeCode($entry->lesson_type_id),
                'topic' => $entry->comment,
            ],
        );
    }

    private function lessonTypeCode(?int $id): string
    {
        return $id ? (ReferenceItem::find($id)?->code ?: 'lesson') : 'lesson';
    }

    private function hasScheduleEntryConflict(string $column, int $value, array $entry, ?int $ignoreEntryId): bool
    {
        if (! $entry['date']) { return false; }
        return ScheduleEntry::query()
            ->where($column, $value)
            ->whereDate('date', $entry['date'])
            ->where('status', '!=', 'canceled')
            ->where('starts_at', '<', $entry['ends_at'])
            ->where('ends_at', '>', $entry['starts_at'])
            ->when($ignoreEntryId, fn (Builder $query) => $query->whereKeyNot($ignoreEntryId))
            ->exists();
    }

    private function hasLegacyLessonConflict(array $entry): bool
    {
        if (! $entry['date']) { return false; }
        return ScheduleLesson::query()
            ->whereNull('schedule_entry_id')
            ->whereDate('lesson_date', $entry['date'])
            ->where('starts_at', '<', $entry['ends_at'])
            ->where('ends_at', '>', $entry['starts_at'])
            ->where(function (Builder $query) use ($entry): void {
                $query->where('group_id', $entry['group_id'])->orWhere('teacher_id', $entry['teacher_id']);
                if ($entry['classroom_id']) { $query->orWhere('classroom_id', $entry['classroom_id']); }
            })
            ->exists();
    }

    private function hasExactDuplicate(array $entry, ?int $ignoreEntryId): bool
    {
        return ScheduleEntry::query()
            ->where('academic_year', $entry['academic_year'])
            ->where('semester', $entry['semester'])
            ->whereDate('date', $entry['date'])
            ->where('starts_at', $entry['starts_at'])
            ->where('ends_at', $entry['ends_at'])
            ->where('group_id', $entry['group_id'])
            ->where('subject_id', $entry['subject_id'])
            ->where('teacher_id', $entry['teacher_id'])
            ->when($ignoreEntryId, fn (Builder $query) => $query->whereKeyNot($ignoreEntryId))
            ->exists();
    }

    private function scheduledHoursForItem(?int $itemId, ?int $ignoreEntryId = null): int
    {
        if (! $itemId) { return 0; }
        return ScheduleEntry::query()
            ->where('teaching_load_item_id', $itemId)
            ->where('status', '!=', 'canceled')
            ->when($ignoreEntryId, fn (Builder $query) => $query->whereKeyNot($ignoreEntryId))
            ->get()
            ->sum(fn (ScheduleEntry $entry) => $this->academicHours($this->timeString($entry->starts_at), $this->timeString($entry->ends_at))); 
    }

    private function academicHours(string $startsAt, string $endsAt): int
    {
        $start = CarbonImmutable::createFromFormat('H:i', substr($startsAt, 0, 5));
        $end = CarbonImmutable::createFromFormat('H:i', substr($endsAt, 0, 5));
        return max(1, (int) ceil($start->diffInMinutes($end) / 45));
    }

    private function conflict(string $type, string $level, string $reason, array $entry): array
    {
        return [
            'type' => $type,
            'level' => $level,
            'object' => $entry['date'] ?: ('day '.$entry['day_of_week']),
            'date' => $entry['date'],
            'time' => trim(($entry['starts_at'] ?: '—').'–'.($entry['ends_at'] ?: '—')),
            'reason' => $reason,
            'recommendation' => $level === 'blocking' ? 'Измените ресурс, дату или время.' : 'Проверьте перед применением.',
        ];
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        foreach (['group_id', 'teacher_id', 'subject_id', 'classroom_id', 'semester'] as $key) {
            if (! empty($filters[$key])) { $query->where($key, $filters[$key]); }
        }
        if (! empty($filters['date'])) { $query->whereDate('date', $filters['date']); }
        if (! empty($filters['date_from'])) { $query->whereDate('date', '>=', $filters['date_from']); }
        if (! empty($filters['date_to'])) { $query->whereDate('date', '<=', $filters['date_to']); }
    }

    private function academicYearForDate(?string $date): string
    {
        $date = $date ? CarbonImmutable::parse($date) : now()->toImmutable();
        $start = $date->month >= 9 ? $date->year : $date->year - 1;
        return $start.'/'.($start + 1);
    }

    private function semesterForDate(?string $date): int
    {
        $month = $date ? CarbonImmutable::parse($date)->month : now()->month;
        return ($month >= 9 || $month <= 1) ? 1 : 2;
    }

    private function timeString(mixed $value): ?string
    {
        if ($value === null) { return null; }
        if ($value instanceof \DateTimeInterface) { return $value->format('H:i'); }
        $value = (string) $value;
        if (preg_match('/(\d{2}:\d{2})/', $value, $matches)) { return $matches[1]; }
        return substr($value, 0, 5);
    }

    private function entryArray(ScheduleEntry $entry): array
    {
        return [
            ...$entry->toArray(),
            'date' => $entry->date?->toDateString(),
            'starts_at' => $this->timeString($entry->starts_at),
            'ends_at' => $this->timeString($entry->ends_at),
        ];
    }
}
