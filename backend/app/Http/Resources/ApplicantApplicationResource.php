<?php

namespace App\Http\Resources;

use App\Models\ApplicantApplicationDocument;
use Illuminate\Http\Request;
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
            'documents_received_count' => $this->whenLoaded('documents', fn () => $this->registryDocumentCounts()['received']),
            'documents_total_count' => $this->whenLoaded('documents', fn () => $this->registryDocumentCounts()['required']),
            'documents_count' => $this->whenLoaded('documents', fn () => $this->registryDocumentCounts()['received']),
            'required_documents_count' => $this->whenLoaded('documents', fn () => $this->registryDocumentCounts()['required']),
            'documents_missing_count' => $this->whenLoaded('documents', fn () => max(0, $this->registryDocumentCounts()['required'] - $this->registryDocumentCounts()['received'])),
            'documents_complete' => $this->whenLoaded('documents', fn () => $this->registryDocumentCounts()['complete']),
            'documents_verified_complete' => $this->whenLoaded('documents', fn () => $this->registryDocumentCounts()['verified_complete']),
            'documents_status' => $this->whenLoaded('documents', fn () => $this->registryDocumentCounts()['status']),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function registryDocumentCounts(): array
    {
        $documents = $this->documents;
        $required = $documents->filter(fn ($document) => (bool) ($document->documentType?->metadata['required'] ?? true));
        $received = $required->filter(fn ($document) => in_array($document->status ?: ($document->is_received ? 'received' : 'missing'), ApplicantApplicationDocument::COMPLETE_STATUSES, true));
        $verified = $required->filter(fn ($document) => ($document->status ?: null) === ApplicantApplicationDocument::STATUS_VERIFIED);
        $requiredCount = $required->count();
        $receivedCount = $received->count();
        $verifiedCount = $verified->count();
        $complete = $requiredCount > 0 && $receivedCount >= $requiredCount;
        $verifiedComplete = $requiredCount > 0 && $verifiedCount >= $requiredCount;

        return [
            'required' => $requiredCount,
            'received' => $receivedCount,
            'complete' => $complete,
            'verified_complete' => $verifiedComplete,
            'status' => $receivedCount === 0 ? 'no_documents' : ($verifiedComplete ? 'verified_complete' : ($complete ? 'complete' : 'incomplete')),
        ];
    }
}
