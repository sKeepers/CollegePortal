<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeachingLoadItem extends Model
{
    protected $fillable = ['teaching_load_id', 'curriculum_subject_id', 'subject_id', 'group_id', 'teacher_id', 'semester', 'hours_total', 'planned_hours', 'assigned_hours', 'unassigned_hours', 'overassigned_hours', 'load_type', 'workload_type_id', 'assignment_status', 'source', 'sort_order'];

    protected function casts(): array
    {
        return ['semester' => 'integer', 'hours_total' => 'integer', 'planned_hours' => 'integer', 'assigned_hours' => 'integer', 'unassigned_hours' => 'integer', 'overassigned_hours' => 'integer', 'sort_order' => 'integer'];
    }

    public function teachingLoad(): BelongsTo
    {
        return $this->belongsTo(TeachingLoad::class);
    }

    public function curriculumSubject(): BelongsTo
    {
        return $this->belongsTo(CurriculumSubject::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function workloadType(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'workload_type_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
