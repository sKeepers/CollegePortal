<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EducationProgramResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'specialty_id' => $this->specialty_id,
            'specialty' => new SpecialtyResource($this->whenLoaded('specialty')),
            'name' => $this->name,
            'year_start' => $this->year_start,
            'study_form' => $this->study_form,
            'study_years' => $this->study_years,
            'is_active' => $this->is_active,
            'description' => $this->description,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
