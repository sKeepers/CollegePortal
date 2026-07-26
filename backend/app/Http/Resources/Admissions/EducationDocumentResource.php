<?php

namespace App\Http\Resources\Admissions;

use App\Services\Admissions\DocumentMaskingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EducationDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $masking = app(DocumentMaskingService::class);
        $canViewFull = (bool) $request->user()?->hasPermission('admissions.document.download_sensitive');

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'applicant_id' => $this->applicant_id,
            'previous_version_id' => $this->previous_version_id,
            'version_number' => $this->version_number,
            'replaced_by_document_id' => $this->replaced_by_document_id,
            'replaced_at' => $this->replaced_at?->toISOString(),
            'document_type_id' => $this->document_type_id,
            'document_type' => new AdmissionReferenceItemResource($this->whenLoaded('documentType')),
            'series_masked' => filled($this->series) ? '****' : null,
            'number_masked' => $masking->documentNumber(null, $this->number),
            'series' => $this->when($canViewFull, $this->series),
            'number' => $this->when($canViewFull, $this->number),
            'issue_date' => $this->issue_date?->toDateString(),
            'document_organization' => $this->document_organization,
            'country_id' => $this->country_id,
            'country' => new AdmissionReferenceItemResource($this->whenLoaded('country')),
            'country_name' => $this->country_name,
            'education_level_id' => $this->education_level_id,
            'education_level' => new AdmissionReferenceItemResource($this->whenLoaded('educationLevel')),
            'graduation_year' => $this->graduation_year,
            'is_original' => $this->is_original,
            'original_received_at' => $this->original_received_at?->toDateString(),
            'average_score' => $this->average_score,
            'average_score_scale' => $this->average_score_scale,
            'has_attachment' => $this->has_attachment,
            'qualification_name' => $this->qualification_name,
            'speciality_name' => $this->speciality_name,
            'registration_number' => $this->registration_number,
            'is_nostrificated' => $this->is_nostrificated,
            'is_primary' => $this->is_primary,
            'verification_status' => $this->verification_status,
            'verification_comment' => $this->verification_comment,
            'fis_uid' => $this->fis_uid,
            'fis_document_type_id' => $this->fis_document_type_id,
            'fis_country_id' => $this->fis_country_id,
            'fis_region_id' => $this->fis_region_id,
            'files_count' => $this->relationLoaded('activeFiles') ? $this->activeFiles->count() : null,
            'files' => AdmissionDocumentFileResource::collection($this->whenLoaded('activeFiles')),
            'verified_by' => $this->verified_by,
            'verified_at' => $this->verified_at?->toISOString(),
            'archived_at' => $this->archived_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
