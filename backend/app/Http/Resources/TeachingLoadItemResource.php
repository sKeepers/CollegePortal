<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeachingLoadItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'teaching_load_id' => $this->teaching_load_id,
            'curriculum_subject_id' => $this->curriculum_subject_id,
            'curriculum_subject' => new CurriculumSubjectResource($this->whenLoaded('curriculumSubject')),
            'subject_id' => $this->subject_id,
            'subject' => new SubjectResource($this->whenLoaded('subject')),
            'group_id' => $this->group_id,
            'group' => new GroupResource($this->whenLoaded('group')),
            'teacher_id' => $this->teacher_id,
            'teacher' => new TeacherResource($this->whenLoaded('teacher')),
            'semester' => $this->semester,
            'hours_total' => $this->hours_total,
            'planned_hours' => $this->planned_hours,
            'assigned_hours' => $this->assigned_hours,
            'unassigned_hours' => $this->unassigned_hours,
            'overassigned_hours' => $this->overassigned_hours,
            'load_type' => $this->load_type,
            'workload_type_id' => $this->workload_type_id,
            'workload_type' => new ReferenceItemResource($this->whenLoaded('workloadType')),
            'assignment_status' => $this->assignment_status,
            'source' => $this->source,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
