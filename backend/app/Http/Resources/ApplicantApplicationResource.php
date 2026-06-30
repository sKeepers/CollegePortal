<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicantApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'education_program_id' => $this->education_program_id,
            'last_name' => $this->last_name,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'birth_date' => $this->birth_date?->toDateString(),
            'phone' => $this->phone,
            'email' => $this->email,
            'education_base' => $this->education_base,
            'status' => $this->status,
            'submitted_at' => $this->submitted_at?->toDateString(),
            'comment' => $this->comment,
            'education_program' => new EducationProgramResource($this->whenLoaded('educationProgram')),
            'events' => ApplicantApplicationEventResource::collection($this->whenLoaded('events')),
            'documents' => ApplicantApplicationDocumentResource::collection($this->whenLoaded('documents')),
            'documents_received_count' => $this->whenLoaded('documents', fn () => $this->documents->where('is_received', true)->count()),
            'documents_total_count' => $this->whenLoaded('documents', fn () => $this->documents->count()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
