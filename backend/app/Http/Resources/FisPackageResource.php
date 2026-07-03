<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FisPackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'package_type' => $this->package_type,
            'year' => $this->year,
            'education_program_id' => $this->education_program_id,
            'status' => $this->status,
            'validation_checked_at' => $this->validation_checked_at?->toISOString(),
            'exported_at' => $this->exported_at?->toISOString(),
            'archived_at' => $this->archived_at?->toISOString(),
            'note' => $this->note,
            'education_program' => new EducationProgramResource($this->whenLoaded('educationProgram')),
            'records' => FisRecordResource::collection($this->whenLoaded('records')),
            'validation_errors' => FisValidationErrorResource::collection($this->whenLoaded('validationErrors')),
            'records_count' => $this->whenCounted('records'),
            'validation_errors_count' => $this->whenCounted('validationErrors'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
