<?php

namespace App\Services\Admissions;

use App\Models\Admissions\AdmissionDocumentFile;
use App\Models\Admissions\EducationDocument;
use App\Models\Admissions\IdentityDocument;

class AdmissionDocumentAudit
{
    public function __construct(private readonly DocumentMaskingService $masking)
    {
    }

    /** @return array<string, mixed> */
    public function identity(IdentityDocument $document): array
    {
        return [
            'id' => $document->id,
            'applicant_id' => $document->applicant_id,
            'person_id' => $document->person_id,
            'previous_version_id' => $document->previous_version_id,
            'version_number' => $document->version_number,
            'replaced_by_document_id' => $document->replaced_by_document_id,
            'document_type_id' => $document->document_type_id,
            'document_masked' => $this->masking->documentNumber($document->series, $document->number),
            'issue_date' => $document->issue_date?->toDateString(),
            'is_primary' => $document->is_primary,
            'verification_status' => $document->verification_status,
            'fis_uid_present' => filled($document->fis_uid),
        ];
    }

    /** @return array<string, mixed> */
    public function education(EducationDocument $document): array
    {
        return [
            'id' => $document->id,
            'applicant_id' => $document->applicant_id,
            'previous_version_id' => $document->previous_version_id,
            'version_number' => $document->version_number,
            'replaced_by_document_id' => $document->replaced_by_document_id,
            'document_type_id' => $document->document_type_id,
            'document_masked' => $this->masking->documentNumber($document->series, $document->number),
            'issue_date' => $document->issue_date?->toDateString(),
            'graduation_year' => $document->graduation_year,
            'is_original' => $document->is_original,
            'average_score' => $document->average_score,
            'qualification_name_present' => filled($document->qualification_name),
            'speciality_name_present' => filled($document->speciality_name),
            'registration_number_masked' => $this->masking->documentNumber(null, $document->registration_number),
            'is_nostrificated' => $document->is_nostrificated,
            'is_primary' => $document->is_primary,
            'verification_status' => $document->verification_status,
            'fis_uid_present' => filled($document->fis_uid),
        ];
    }

    /** @return array<string, mixed> */
    public function file(AdmissionDocumentFile $file): array
    {
        return [
            'id' => $file->id,
            'applicant_id' => $file->applicant_id,
            'application_id' => $file->application_id,
            'identity_document_id' => $file->identity_document_id,
            'education_document_id' => $file->education_document_id,
            'category' => $file->category,
            'original_name' => $file->original_name,
            'mime_type' => $file->mime_type,
            'extension' => $file->extension,
            'size_bytes' => $file->size_bytes,
            'sha256' => $file->sha256,
            'archived_at' => $file->archived_at?->toISOString(),
        ];
    }
}
