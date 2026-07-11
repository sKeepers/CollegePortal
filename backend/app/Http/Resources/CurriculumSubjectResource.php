<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CurriculumSubjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'curriculum_id' => $this->curriculum_id,
            'semester' => $this->semester,
            'subject_id' => $this->subject_id,
            'subject' => new SubjectResource($this->whenLoaded('subject')),
            'lecture_hours' => $this->lecture_hours,
            'practice_hours' => $this->practice_hours,
            'laboratory_hours' => $this->laboratory_hours,
            'independent_hours' => $this->independent_hours,
            'total_hours' => $this->total_hours,
            'control_type_id' => $this->control_type_id,
            'control_type' => $this->control_type,
            'control_type_item' => new ReferenceItemResource($this->whenLoaded('controlType')),
            'sequence' => $this->sequence,
            'is_optional' => $this->is_optional,
            'competencies' => $this->competencies ?? [],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
