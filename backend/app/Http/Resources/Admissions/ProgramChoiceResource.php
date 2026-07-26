<?php

namespace App\Http\Resources\Admissions;

use App\Http\Resources\EducationProgramResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgramChoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_id' => $this->application_id,
            'priority' => $this->priority,
            'is_primary' => $this->is_primary,
            'education_program_id' => $this->education_program_id,
            'education_program' => new EducationProgramResource($this->whenLoaded('educationProgram')),
            'specialty' => $this->whenLoaded('specialty', fn () => [
                'id' => $this->specialty?->id,
                'code' => $this->specialty?->code,
                'name' => $this->specialty?->name,
            ]),
            'education_form' => $this->referencePayload('educationForm'),
            'funding_form' => $this->referencePayload('fundingForm'),
            'base_education_type' => $this->referencePayload('baseEducationType'),
            'quota_type' => $this->referencePayload('quotaType'),
            'status' => $this->referencePayload('status'),
            'metadata' => $this->metadata,
            'archived_at' => $this->archived_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function referencePayload(string $relation): mixed
    {
        return $this->whenLoaded($relation, fn () => [
            'id' => $this->{$relation}?->id,
            'code' => $this->{$relation}?->code,
            'name' => $this->{$relation}?->name,
        ]);
    }
}
