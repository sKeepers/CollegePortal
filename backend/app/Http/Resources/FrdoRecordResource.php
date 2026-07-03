<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FrdoRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'frdo_package_id' => $this->frdo_package_id,
            'graduate_id' => $this->graduate_id,
            'diploma_id' => $this->diploma_id,
            'diploma_supplement_id' => $this->diploma_supplement_id,
            'education_program_id' => $this->education_program_id,
            'specialty_id' => $this->specialty_id,
            'status' => $this->status,
            'payload' => $this->payload,
            'graduate' => new GraduateResource($this->whenLoaded('graduate')),
            'diploma' => new DiplomaResource($this->whenLoaded('diploma')),
            'education_program' => new EducationProgramResource($this->whenLoaded('educationProgram')),
            'specialty' => new SpecialtyResource($this->whenLoaded('specialty')),
            'validation_errors' => FrdoValidationErrorResource::collection($this->whenLoaded('validationErrors')),
            'validation_errors_count' => $this->whenCounted('validationErrors'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
