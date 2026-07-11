<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CurriculumResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'education_program_id' => $this->education_program_id,
            'education_program' => new EducationProgramResource($this->whenLoaded('educationProgram')),
            'name' => $this->name,
            'qualification' => $this->qualification,
            'year_start' => $this->year_start,
            'status' => $this->status,
            'description' => $this->description,
            'competencies' => $this->competencies ?? [],
            'subjects' => CurriculumSubjectResource::collection($this->whenLoaded('subjects')),
            'subjects_count' => $this->whenCounted('subjects'),
            'items' => CurriculumItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->whenCounted('items'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
