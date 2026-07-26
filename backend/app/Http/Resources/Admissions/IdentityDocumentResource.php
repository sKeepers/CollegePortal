<?php

namespace App\Http\Resources\Admissions;

use App\Services\Admissions\DocumentMaskingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IdentityDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $masking = app(DocumentMaskingService::class);
        $canViewFull = (bool) $request->user()?->hasPermission('admissions.document.download_sensitive');

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'applicant_id' => $this->applicant_id,
            'person_id' => $this->person_id,
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
            'issued_by' => $this->issued_by,
            'subdivision_code' => $this->subdivision_code,
            'release_country_id' => $this->release_country_id,
            'release_country' => new AdmissionReferenceItemResource($this->whenLoaded('releaseCountry')),
            'release_country_name' => $this->release_country_name,
            'release_place' => $this->release_place,
            'valid_until' => $this->valid_until?->toDateString(),
            'is_primary' => $this->is_primary,
            'verification_status' => $this->verification_status,
            'verification_comment' => $this->verification_comment,
            'fis_uid' => $this->fis_uid,
            'fis_identity_document_type_id' => $this->fis_identity_document_type_id,
            'fis_nationality_type_id' => $this->fis_nationality_type_id,
            'fis_release_country_id' => $this->fis_release_country_id,
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
