<?php

namespace App\Services;

use App\Models\CurriculumSubject;
use App\Models\Group;
use App\Models\ReferenceItem;
use App\Models\Teacher;
use App\Models\TeachingLoad;
use App\Models\TeachingLoadItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TeachingLoadGenerationService
{
    public const SOURCE = 'curriculum_engine';

    public function preview(int $groupId, string $academicYear): array
    {
        return $this->build($groupId, $academicYear, apply: false);
    }

    public function apply(int $groupId, string $academicYear, ?User $user = null): array
    {
        return DB::transaction(fn () => $this->build($groupId, $academicYear, apply: true, user: $user));
    }

    public function coverage(TeachingLoad $load): array
    {
        $items = $load->items()->get();

        return [
            'teaching_load_id' => $load->id,
            'planned_hours' => $items->sum('planned_hours'),
            'assigned_hours' => $items->sum('assigned_hours'),
            'unassigned_hours' => $items->sum('unassigned_hours'),
            'overassigned_hours' => $items->sum('overassigned_hours'),
            'items_count' => $items->count(),
            'unassigned_count' => $items->where('assignment_status', 'unassigned')->count(),
            'partially_assigned_count' => $items->where('assignment_status', 'partially_assigned')->count(),
            'assigned_count' => $items->where('assignment_status', 'assigned')->count(),
            'overassigned_count' => $items->where('assignment_status', 'overassigned')->count(),
        ];
    }

    public function assignTeacher(TeachingLoadItem $item, int $teacherId, ?int $assignedHours = null, ?User $user = null): TeachingLoadItem
    {
        $old = $item->getAttributes();
        $planned = (int) ($item->planned_hours ?: $item->hours_total);
        $assigned = $assignedHours ?? $planned;
        $item->update([
            'teacher_id' => $teacherId,
            'assigned_hours' => max(0, $assigned),
            ...$this->hourStatus($planned, max(0, $assigned)),
        ]);
        AuditLogService::log('Teaching Load', 'teaching_load_item_assigned', $item, $old, $item->getAttributes(), user: $user);

        return $item->fresh(['subject', 'group', 'teacher', 'curriculumSubject', 'workloadType']);
    }

    /** @param array<int> $ids */
    public function bulkAssignTeacher(array $ids, int $teacherId, ?int $assignedHours = null, ?User $user = null): array
    {
        return DB::transaction(function () use ($ids, $teacherId, $assignedHours, $user): array {
            $changed = 0;
            $items = TeachingLoadItem::query()->whereIn('id', $ids)->get();
            foreach ($items as $item) {
                $this->assignTeacher($item, $teacherId, $assignedHours, $user);
                $changed++;
            }
            AuditLogService::log('Teaching Load', 'teaching_load_items_bulk_assigned', ['type' => 'TeachingLoadItem', 'id' => null], null, ['ids' => array_values($ids), 'teacher_id' => $teacherId, 'changed' => $changed], user: $user);

            return ['selected' => count($ids), 'changed' => $changed];
        });
    }

    private function build(int $groupId, string $academicYear, bool $apply, ?User $user = null): array
    {
        $group = Group::query()->with('curriculum.subjects.subject')->findOrFail($groupId);
        if (! $group->curriculum_id || ! $group->curriculum) {
            return $this->emptyReport($group, $academicYear, 'У группы не назначен учебный план.');
        }

        $subjects = $group->curriculum->subjects()->with(['subject', 'controlType'])->get();
        if ($subjects->isEmpty()) {
            return $this->emptyReport($group, $academicYear, 'В учебном плане нет дисциплин семестров.');
        }

        $load = TeachingLoad::query()
            ->where('academic_year', $academicYear)
            ->where('group_id', $group->id)
            ->where('curriculum_id', $group->curriculum_id)
            ->whereNull('teacher_id')
            ->first();

        $report = [
            'group' => ['id' => $group->id, 'name' => $group->name],
            'curriculum' => ['id' => $group->curriculum->id, 'name' => $group->curriculum->name],
            'academic_year' => $academicYear,
            'teaching_load_id' => $load?->id,
            'found' => $subjects->count(),
            'will_create' => 0,
            'will_update' => 0,
            'conflicts' => 0,
            'unassigned_teachers' => 0,
            'items' => [],
        ];

        if ($apply && ! $load) {
            $load = TeachingLoad::create([
                'academic_year' => $academicYear,
                'teacher_id' => null,
                'curriculum_id' => $group->curriculum_id,
                'group_id' => $group->id,
                'status' => 'draft',
                'description' => 'Сформировано из учебного плана.',
                'generated_at' => now(),
                'generated_by' => $user?->id,
            ]);
            $report['teaching_load_id'] = $load->id;
        }

        foreach ($subjects as $subject) {
            $existing = $load
                ? $load->items()->where('curriculum_subject_id', $subject->id)->first()
                : null;
            $manual = TeachingLoadItem::query()
                ->where('group_id', $group->id)
                ->where('subject_id', $subject->subject_id)
                ->where('semester', $subject->semester)
                ->where('source', '!=', self::SOURCE)
                ->exists();
            $planned = (int) $subject->total_hours;
            $assigned = (int) ($existing?->assigned_hours ?? 0);
            $status = $this->hourStatus($planned, $assigned);
            $operation = $existing ? 'update' : 'create';
            $report[$existing ? 'will_update' : 'will_create']++;
            if ($manual) { $report['conflicts']++; }
            if (! $existing?->teacher_id) { $report['unassigned_teachers']++; }

            $report['items'][] = [
                'curriculum_subject_id' => $subject->id,
                'semester' => $subject->semester,
                'subject_id' => $subject->subject_id,
                'subject_name' => $subject->subject?->name,
                'control_type' => $subject->control_type,
                'planned_hours' => $planned,
                'existing_hours' => $existing?->planned_hours ?? 0,
                'assigned_hours' => $assigned,
                'unassigned_hours' => $status['unassigned_hours'],
                'overassigned_hours' => $status['overassigned_hours'],
                'assignment_status' => $status['assignment_status'],
                'teacher_id' => $existing?->teacher_id,
                'operation' => $operation,
                'conflict' => $manual,
                'warning' => $existing?->teacher_id ? null : 'Преподаватель не назначен.',
            ];

            if ($apply && $load) {
                $payload = [
                    'subject_id' => $subject->subject_id,
                    'group_id' => $group->id,
                    'semester' => $subject->semester,
                    'hours_total' => $planned,
                    'planned_hours' => $planned,
                    'assigned_hours' => $assigned,
                    ...$status,
                    'load_type' => $this->loadTypeName(),
                    'workload_type_id' => $this->loadTypeId(),
                    'source' => self::SOURCE,
                    'sort_order' => $subject->sequence,
                ];

                // Строка уже найдена выше — переиспользуем её, а не спрашиваем
                // базу второй раз. Заодно уходит точка сохранения: с Laravel
                // 10.31 `updateOrCreate` создаёт строку через `createOrFirst`, а
                // тот внутри открытой транзакции оборачивает вставку в
                // `SAVEPOINT`. На одном вызове это незаметно, но набор зовёт
                // генератор по разу на группу, и в прогоне под `RefreshDatabase`
                // отсюда набиралось 1440 точек сохранения из 4970 — почти треть
                // (замер 12.08.2026).
                $item = $existing ?? $load->items()->make(['curriculum_subject_id' => $subject->id]);
                $item->fill($payload)->save();
                AuditLogService::log('Teaching Load', $operation === 'create' ? 'teaching_load_item_generated' : 'teaching_load_item_regenerated', $item, null, $item->getAttributes(), user: $user);
            }
        }

        if ($apply && $load) {
            $load->update(['generated_at' => now(), 'generated_by' => $user?->id]);
            AuditLogService::log('Teaching Load', 'teaching_load_generated_from_curriculum', $load, null, $report, user: $user);
        }

        return $report;
    }

    private function emptyReport(Group $group, string $academicYear, string $reason): array
    {
        return [
            'group' => ['id' => $group->id, 'name' => $group->name],
            'curriculum' => null,
            'academic_year' => $academicYear,
            'found' => 0,
            'will_create' => 0,
            'will_update' => 0,
            'conflicts' => 0,
            'unassigned_teachers' => 0,
            'errors' => 1,
            'reason' => $reason,
            'items' => [],
        ];
    }

    private function hourStatus(int $planned, int $assigned): array
    {
        $unassigned = max(0, $planned - $assigned);
        $over = max(0, $assigned - $planned);
        $status = match (true) {
            $assigned <= 0 => 'unassigned',
            $over > 0 => 'overassigned',
            $unassigned > 0 => 'partially_assigned',
            default => 'assigned',
        };

        return ['unassigned_hours' => $unassigned, 'overassigned_hours' => $over, 'assignment_status' => $status];
    }

    private function loadTypeId(): ?int
    {
        return ReferenceItem::query()->whereHas('catalog', fn ($query) => $query->where('code', 'teaching_load_types'))->where('code', 'classroom')->value('id');
    }

    private function loadTypeName(): string
    {
        return ReferenceItem::query()->whereHas('catalog', fn ($query) => $query->where('code', 'teaching_load_types'))->where('code', 'classroom')->value('name') ?: 'Аудиторная';
    }
}
