<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeachingLoadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'academic_year' => $this->academic_year,
            'teacher_id' => $this->teacher_id,
            'teacher' => new TeacherResource($this->whenLoaded('teacher')),
            'curriculum_id' => $this->curriculum_id,
            'curriculum' => new CurriculumResource($this->whenLoaded('curriculum')),
            'group_id' => $this->group_id,
            'group' => new GroupResource($this->whenLoaded('group')),
            'status' => $this->status,
            'description' => $this->description,
            'generated_at' => $this->generated_at?->toISOString(),
            'generated_by' => $this->generated_by,
            'coverage' => $this->whenLoaded('items', fn () => [
                'planned_hours' => $this->items->sum('planned_hours'),
                'assigned_hours' => $this->items->sum('assigned_hours'),
                'unassigned_hours' => $this->items->sum('unassigned_hours'),
                'overassigned_hours' => $this->items->sum('overassigned_hours'),
            ]),
            'items' => TeachingLoadItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->whenCounted('items'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
