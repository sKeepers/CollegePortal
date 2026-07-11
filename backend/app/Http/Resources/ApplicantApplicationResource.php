<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Services\ApplicantApplicationDocumentService;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicantApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'person_id' => $this->person_id,
            'education_program_id' => $this->education_program_id,
            'external_source' => $this->external_source,
            'external_application_number' => $this->external_application_number,
            'external_status' => $this->external_status,
            'external_registered_at' => $this->external_registered_at?->toDateString(),
            'competition_name' => $this->competition_name,
            'last_name' => $this->last_name,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'birth_date' => $this->birth_date?->toDateString(),
            'phone' => $this->phone,
            'email' => $this->email,
            'education_base' => $this->education_base,
            'education_form' => $this->education_form,
            'funding_form' => $this->funding_form,
            'status' => $this->status,
            'submitted_at' => $this->submitted_at?->toDateString(),
            'certificate_average_score' => $this->certificate_average_score,
            'achievement_score' => $this->achievement_score,
            'ranking_score' => $this->ranking_score,
            'documents_provided' => $this->documents_provided,
            'recommended_for_enrollment' => $this->recommended_for_enrollment,
            'comment' => $this->comment,
            'education_program' => new EducationProgramResource($this->whenLoaded('educationProgram')),
            'events' => ApplicantApplicationEventResource::collection($this->whenLoaded('events')),
            'documents' => ApplicantApplicationDocumentResource::collection($this->whenLoaded('documents')),
            'documents_received_count' => $this->whenLoaded('documents', fn () => $this->documents->where('is_received', true)->count()),
            'documents_total_count' => $this->whenLoaded('documents', fn () => $this->documents->count()),
            'documents_count' => $this->whenLoaded('documents', fn () => $this->documents->where('is_received', true)->count()),
            'required_documents_count' => $this->whenLoaded('documents', fn () => max(ApplicantApplicationDocumentService::REQUIRED_DOCUMENTS_COUNT, $this->documents->count())),
            'documents_missing_count' => $this->whenLoaded('documents', fn () => max(0, max(ApplicantApplicationDocumentService::REQUIRED_DOCUMENTS_COUNT, $this->documents->count()) - $this->documents->where('is_received', true)->count())),
            'documents_complete' => $this->whenLoaded('documents', fn () => $this->documents->where('is_received', true)->count() >= max(ApplicantApplicationDocumentService::REQUIRED_DOCUMENTS_COUNT, $this->documents->count())),
            'documents_status' => $this->whenLoaded('documents', function () {
                $received = $this->documents->where('is_received', true)->count();
                $required = max(ApplicantApplicationDocumentService::REQUIRED_DOCUMENTS_COUNT, $this->documents->count());

                return $received === 0 ? 'no_documents' : ($received >= $required ? 'complete' : 'incomplete');
            }),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
