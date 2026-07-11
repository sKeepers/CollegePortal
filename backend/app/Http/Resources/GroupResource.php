<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'specialty' => $this->specialty,
            'education_program_id' => $this->education_program_id,
            'education_program' => new EducationProgramResource($this->whenLoaded('educationProgram')),
            'curriculum_id' => $this->curriculum_id,
            'curriculum' => new CurriculumResource($this->whenLoaded('curriculum')),
            'course' => $this->course,
            'year_start' => $this->year_start,
            'curator_id' => $this->curator_id,
            'curator' => new TeacherResource($this->whenLoaded('curator')),
            'students_count' => $this->whenCounted('students'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
