<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeStatusPeriod;
use App\Models\HrEvent;
use App\Models\ScheduleEntry;
use App\Models\Teacher;
use App\Models\TeachingLoadItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HrAbsenceService
{
    public const ABSENCE_TYPES = ['vacation', 'sick_leave', 'maternity_leave', 'business_trip', 'suspended', 'dismissed'];
    public const PERIOD_STATUSES = ['planned', 'active', 'completed', 'cancelled'];

    public function __construct(private readonly ScheduleEngineService $scheduleEngine)
    {
    }

    public function calendar(array $filters = []): array
    {
        $dateFrom = $filters['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $filters['date_to'] ?? now()->endOfMonth()->toDateString();

        $periods = EmployeeStatusPeriod::query()
            ->with(['employee.person', 'employee.primaryDepartment', 'employee.primaryPosition', 'employee.teacher'])
            ->where('period_status', '!=', 'cancelled')
            ->whereDate('date_from', '<=', $dateTo)
            ->where(fn (Builder $query) => $query->whereNull('date_to')->orWhereDate('date_to', '>=', $dateFrom))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['period_status'] ?? null, fn (Builder $query, string $status) => $query->where('period_status', $status))
            ->when($filters['department_id'] ?? null, fn (Builder $query, int|string $id) => $query->whereHas('employee', fn ($q) => $q->where('primary_department_id', $id)))
            ->when($filters['employee_id'] ?? null, fn (Builder $query, int|string $id) => $query->where('employee_id', $id))
            ->orderBy('date_from')
            ->get();

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'periods' => $periods->map(fn (EmployeeStatusPeriod $period) => $this->periodSummary($period))->values()->all(),
            'summary' => $this->summary($dateFrom, $dateTo),
        ];
    }

    public function previewPeriod(Employee $employee, array $data, ?EmployeeStatusPeriod $ignore = null): array
    {
        $data = $this->normalizePeriodPayload($data);
        $conflicts = $this->periodConflicts($employee, $data, $ignore);
        $affected = $this->affectedLessonsForPayload($employee, $data);
        $blocking = collect($conflicts)->where('level', 'blocking')->count();

        return [
            'employee' => $this->employeeSummary($employee),
            'period' => $data,
            'can_apply' => $blocking === 0,
            'blocking_count' => $blocking,
            'warning_count' => collect($conflicts)->where('level', 'warning')->count(),
            'conflicts' => $conflicts,
            'affected_lessons_count' => count($affected),
            'affected_lessons' => $affected,
        ];
    }

    public function applyPeriod(Employee $employee, array $data, User $user): EmployeeStatusPeriod
    {
        return DB::transaction(function () use ($employee, $data, $user): EmployeeStatusPeriod {
            $preview = $this->previewPeriod($employee, $data);
            if (! $preview['can_apply']) {
                throw ValidationException::withMessages(['period' => 'Есть блокирующие конфликты кадрового периода.']);
            }

            $payload = $preview['period'];
            $period = $employee->statusPeriods()->create([...$payload, 'created_by' => $user->id]);
            if ($period->status === 'dismissed') {
                $employee->update(['status' => 'dismissed', 'dismissed_at' => $period->date_from?->toDateString()]);
            } else {
                $employee->update(['status' => $employee->statusOn(now()->toDateString())]);
            }

            $this->event('hr_period_created', $period, $user, ['affected_lessons_count' => $preview['affected_lessons_count']]);
            if ($preview['affected_lessons_count'] > 0) {
                $this->event('teacher_unavailable', $period, $user, ['affected_lessons_count' => $preview['affected_lessons_count']], 'warning');
                $this->event('replacement_required', $period, $user, ['lessons' => $preview['affected_lessons_count']], 'warning');
            }
            AuditLogService::log('hr', 'employee_status_period_applied', $period, null, $period->toArray(), user: $user);

            return $period->fresh(['employee.person', 'employee.teacher']);
        });
    }

    public function cancelPeriod(EmployeeStatusPeriod $period, User $user, ?string $reason = null): EmployeeStatusPeriod
    {
        $old = $period->toArray();
        $period->update(['period_status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $user->id, 'cancel_reason' => $reason]);
        $this->event('hr_period_cancelled', $period, $user, ['reason' => $reason]);
        AuditLogService::log('hr', 'employee_status_period_cancelled', $period, $old, $period->fresh()->toArray(), user: $user);
        return $period->fresh(['employee.person', 'employee.teacher']);
    }

    public function affectedLessons(EmployeeStatusPeriod $period): array
    {
        return $this->affectedLessonsForPayload($period->employee()->with('teacher')->firstOrFail(), [
            'status' => $period->status,
            'date_from' => $period->date_from?->toDateString(),
            'date_to' => $period->date_to?->toDateString(),
        ]);
    }

    public function replacementCandidates(ScheduleEntry $entry, Employee $absentEmployee): array
    {
        $entry->loadMissing(['subject', 'teacher.employee.primaryDepartment', 'classroom', 'group']);
        return Teacher::query()
            ->with(['employee.statusPeriods', 'employee.primaryDepartment', 'subjects'])
            ->whereKeyNot($entry->teacher_id)
            ->where('is_active', true)
            ->get()
            ->map(fn (Teacher $teacher) => $this->candidateScore($teacher, $entry, $absentEmployee))
            ->sortByDesc('score')
            ->values()
            ->all();
    }

    public function replacementPreview(array $items): array
    {
        $results = [];
        foreach ($items as $item) {
            $entry = ScheduleEntry::query()->with(['group', 'subject', 'teacher', 'classroom', 'teachingLoadItem'])->findOrFail((int) $item['schedule_entry_id']);
            $teacher = Teacher::query()->with('employee.statusPeriods')->findOrFail((int) $item['teacher_id']);
            $payload = [...$entry->toArray(), 'teacher_id' => $teacher->id, 'is_replacement' => true, 'replaced_entry_id' => $entry->replaced_entry_id ?: $entry->id, 'date' => $entry->date?->toDateString(), 'starts_at' => $this->time($entry->starts_at), 'ends_at' => $this->time($entry->ends_at)];
            $preview = $this->scheduleEngine->preview($payload, $entry->id);
            $results[] = ['schedule_entry_id' => $entry->id, 'teacher_id' => $teacher->id, 'can_apply' => $preview['can_apply'], 'conflicts' => $preview['conflicts']];
        }
        return ['items' => $results, 'can_apply' => collect($results)->every(fn ($item) => $item['can_apply'])];
    }

    public function applyReplacements(array $items, User $user): array
    {
        return DB::transaction(function () use ($items, $user): array {
            $preview = $this->replacementPreview($items);
            $report = ['applied' => 0, 'errors' => [], 'items' => $preview['items']];
            foreach ($preview['items'] as $item) {
                if (! $item['can_apply']) {
                    $report['errors'][] = ['schedule_entry_id' => $item['schedule_entry_id'], 'reason' => 'Есть конфликты замены.'];
                    continue;
                }
                $entry = ScheduleEntry::findOrFail($item['schedule_entry_id']);
                $updated = $this->scheduleEngine->replaceTeacher($entry, (int) $item['teacher_id'], $user);
                $report['applied']++;
                HrEvent::create(['event_type' => 'replacement_assigned', 'schedule_entry_id' => $updated->id, 'teacher_id' => $updated->teacher_id, 'payload' => ['old_teacher_id' => $entry->teacher_id], 'severity' => 'info', 'created_by' => $user->id]);
                AuditLogService::log('hr', 'teacher_replacement_applied', $updated, ['teacher_id' => $entry->teacher_id], ['teacher_id' => $updated->teacher_id], user: $user);
            }
            return $report;
        });
    }

    public function reportRows(array $filters = []): array
    {
        return $this->calendar($filters)['periods'];
    }

    public function dashboardKpi(): array
    {
        $today = now()->toDateString();
        $todayPeriods = EmployeeStatusPeriod::query()->where('period_status', '!=', 'cancelled')->whereDate('date_from', '<=', $today)->where(fn ($q) => $q->whereNull('date_to')->orWhereDate('date_to', '>=', $today));
        $endingSoon = EmployeeStatusPeriod::query()->where('period_status', 'active')->whereNotNull('date_to')->whereBetween('date_to', [$today, now()->addDays(7)->toDateString()])->count();
        $lessonsWithoutReplacement = collect($this->calendar(['date_from' => $today, 'date_to' => $today])['periods'])->sum('affected_lessons_count');
        return [
            'absent_today' => (clone $todayPeriods)->count(),
            'sick_leave_today' => (clone $todayPeriods)->where('status', 'sick_leave')->count(),
            'vacation_today' => (clone $todayPeriods)->where('status', 'vacation')->count(),
            'business_trip_today' => (clone $todayPeriods)->where('status', 'business_trip')->count(),
            'lessons_without_replacement' => $lessonsWithoutReplacement,
            'replacements_pending' => HrEvent::query()->where('event_type', 'replacement_required')->whereNull('read_at')->count(),
            'periods_ending_soon' => $endingSoon,
        ];
    }

    private function periodConflicts(Employee $employee, array $data, ?EmployeeStatusPeriod $ignore = null): array
    {
        $conflicts = [];
        $from = $data['date_from'];
        $to = $data['date_to'];
        if ($data['status'] === 'dismissed' && $employee->hired_at && $from < $employee->hired_at->toDateString()) {
            $conflicts[] = $this->conflict('dismissal_before_hire', 'blocking', 'Дата увольнения раньше даты приема.');
        }
        if ($to === null && $data['status'] !== 'dismissed') {
            $conflicts[] = $this->conflict('open_period', 'warning', 'Период без даты окончания требует ручного контроля.');
        }
        $overlap = $employee->statusPeriods()->where('period_status', '!=', 'cancelled')
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id))
            ->whereDate('date_from', '<=', $to ?: '9999-12-31')
            ->where(fn ($q) => $q->whereNull('date_to')->orWhereDate('date_to', '>=', $from))
            ->exists();
        if ($overlap) {
            $conflicts[] = $this->conflict('period_overlap', 'blocking', 'Период пересекается с другим кадровым периодом.');
        }
        $active = $employee->statusOn($from);
        if (in_array($active, Employee::UNAVAILABLE_STATUSES, true) && $active !== $data['status']) {
            $conflicts[] = $this->conflict('active_status_conflict', 'warning', 'На дату начала уже есть активный кадровый статус.');
        }
        return $conflicts;
    }

    private function affectedLessonsForPayload(Employee $employee, array $data): array
    {
        $teacher = $employee->teacher;
        if (! $teacher) { return []; }
        $dateTo = $data['date_to'] ?: $data['date_from'];
        return ScheduleEntry::query()
            ->with(['group', 'subject', 'classroom', 'teacher'])
            ->where('teacher_id', $teacher->id)
            ->where('status', '!=', 'canceled')
            ->whereDate('date', '>=', $data['date_from'])
            ->whereDate('date', '<=', $dateTo)
            ->orderBy('date')
            ->orderBy('starts_at')
            ->get()
            ->map(fn (ScheduleEntry $entry) => $this->lessonSummary($entry))
            ->values()
            ->all();
    }

    private function candidateScore(Teacher $teacher, ScheduleEntry $entry, Employee $absentEmployee): array
    {
        $sameDiscipline = $teacher->subjects->contains('id', $entry->subject_id) || TeachingLoadItem::query()->where('teacher_id', $teacher->id)->where('subject_id', $entry->subject_id)->exists();
        $hasLoad = TeachingLoadItem::query()->where('teacher_id', $teacher->id)->where('subject_id', $entry->subject_id)->where('group_id', $entry->group_id)->exists();
        $hours = TeachingLoadItem::query()->where('teacher_id', $teacher->id)->sum('unassigned_hours');
        $conflict = ScheduleEntry::query()->where('teacher_id', $teacher->id)->whereDate('date', $entry->date)->where('status', '!=', 'canceled')->get()->contains(fn (ScheduleEntry $candidateEntry) => $this->time($candidateEntry->starts_at) < $this->time($entry->ends_at) && $this->time($candidateEntry->ends_at) > $this->time($entry->starts_at));
        $hrUnavailable = $teacher->employee && in_array($teacher->employee->statusOn($entry->date?->toDateString()), Employee::UNAVAILABLE_STATUSES, true);
        $sameDepartment = $teacher->employee?->primary_department_id && $teacher->employee?->primary_department_id === $absentEmployee->primary_department_id;
        $score = 0;
        $score += $sameDiscipline ? 40 : 0;
        $score += $hours > 0 ? 20 : 0;
        $score += ! $conflict ? 20 : -50;
        $score += $sameDepartment ? 10 : 0;
        $score += $hasLoad ? 10 : -10;
        $score += $hrUnavailable ? -100 : 0;
        return [
            'teacher_id' => $teacher->id,
            'full_name' => trim("{$teacher->last_name} {$teacher->first_name} {$teacher->middle_name}"),
            'score' => $score,
            'result' => $score > 0 && ! $conflict && ! $hrUnavailable ? 'подходит' : 'требует проверки',
            'reasons' => [
                'same_discipline' => $sameDiscipline,
                'available_hours' => $hours,
                'schedule_conflict' => $conflict,
                'same_department' => $sameDepartment,
                'has_load' => $hasLoad,
                'hr_unavailable' => $hrUnavailable,
            ],
        ];
    }

    private function normalizePeriodPayload(array $data): array
    {
        $from = Carbon::parse($data['date_from'])->toDateString();
        $to = ! empty($data['date_to']) ? Carbon::parse($data['date_to'])->toDateString() : null;
        $today = now()->toDateString();
        $periodStatus = $data['period_status'] ?? ($from > $today ? 'planned' : (($to && $to < $today) ? 'completed' : 'active'));
        return [
            'status' => $data['status'],
            'period_status' => $periodStatus,
            'date_from' => $from,
            'date_to' => $to,
            'reason' => $data['reason'] ?? null,
            'document_number' => $data['document_number'] ?? null,
            'document_date' => $data['document_date'] ?? null,
            'comment' => $data['comment'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ];
    }

    private function periodSummary(EmployeeStatusPeriod $period): array
    {
        return [
            'id' => $period->id,
            'employee_id' => $period->employee_id,
            'employee_name' => $this->employeeName($period->employee),
            'department' => $period->employee?->primaryDepartment?->name,
            'position' => $period->employee?->primaryPosition?->name,
            'teacher_id' => $period->employee?->teacher?->id,
            'status' => $period->status,
            'period_status' => $period->period_status,
            'date_from' => $period->date_from?->toDateString(),
            'date_to' => $period->date_to?->toDateString(),
            'reason' => $period->reason,
            'affected_lessons_count' => count($this->affectedLessons($period)),
        ];
    }

    private function lessonSummary(ScheduleEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'date' => $entry->date?->toDateString(),
            'starts_at' => $this->time($entry->starts_at),
            'ends_at' => $this->time($entry->ends_at),
            'group' => $entry->group?->name,
            'subject' => $entry->subject?->name,
            'classroom' => $entry->classroom?->number,
            'status' => $entry->status,
            'teacher_id' => $entry->teacher_id,
            'is_replacement' => (bool) $entry->is_replacement,
            'replaced_entry_id' => $entry->replaced_entry_id,
        ];
    }

    private function summary(string $dateFrom, string $dateTo): array
    {
        $query = EmployeeStatusPeriod::query()->where('period_status', '!=', 'cancelled')->whereDate('date_from', '<=', $dateTo)->where(fn ($q) => $q->whereNull('date_to')->orWhereDate('date_to', '>=', $dateFrom));
        return [
            'total' => (clone $query)->count(),
            'vacation' => (clone $query)->where('status', 'vacation')->count(),
            'sick_leave' => (clone $query)->where('status', 'sick_leave')->count(),
            'business_trip' => (clone $query)->where('status', 'business_trip')->count(),
            'dismissed' => (clone $query)->where('status', 'dismissed')->count(),
        ];
    }

    private function event(string $type, EmployeeStatusPeriod $period, User $user, array $payload = [], string $severity = 'info'): void
    {
        HrEvent::create(['event_type' => $type, 'employee_id' => $period->employee_id, 'employee_status_period_id' => $period->id, 'teacher_id' => $period->employee?->teacher?->id, 'payload' => $payload, 'severity' => $severity, 'created_by' => $user->id]);
    }

    private function employeeSummary(Employee $employee): array { return ['id' => $employee->id, 'full_name' => $this->employeeName($employee), 'teacher_id' => $employee->teacher?->id]; }
    private function employeeName(?Employee $employee): string { $person = $employee?->person; return trim("{$person?->last_name} {$person?->first_name} {$person?->middle_name}") ?: (string) $employee?->employee_number; }
    private function conflict(string $type, string $level, string $message): array { return ['type' => $type, 'level' => $level, 'message' => $message]; }
    private function time(mixed $value): ?string { return $value instanceof \DateTimeInterface ? $value->format('H:i') : ($value ? substr((string) $value, 0, 5) : null); }
}
