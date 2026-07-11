<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class GraduateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'person_id' => $this->person_id,
            'student_id' => $this->student_id,
            'group_id' => $this->group_id,
            'education_program_id' => $this->education_program_id,
            'specialty_id' => $this->specialty_id,
            'graduation_year' => $this->graduation_year,
            'qualification' => $this->qualification,
            'photo_path' => $this->photo_path,
            'photo_url' => $this->photo_path ? $request->getSchemeAndHttpHost().Storage::disk('public')->url($this->photo_path) : null,
            'status' => $this->status,
            'note' => $this->note,
            'student' => new StudentResource($this->whenLoaded('student')),
            'group' => new GroupResource($this->whenLoaded('group')),
            'education_program' => new EducationProgramResource($this->whenLoaded('educationProgram')),
            'specialty' => new SpecialtyResource($this->whenLoaded('specialty')),
            'diploma' => new DiplomaResource($this->whenLoaded('diploma')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
