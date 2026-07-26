<?php

namespace App\Repositories\Admissions;

use App\Models\Admissions\AdmissionDocumentFile;

class AdmissionDocumentFileRepository
{
    public function find(int $id, bool $withArchived = false): ?AdmissionDocumentFile
    {
        return AdmissionDocumentFile::query()
            ->with(['applicant.person', 'identityDocument', 'educationDocument', 'uploader'])
            ->when(! $withArchived, fn ($query) => $query->active())
            ->find($id);
    }

    public function activeDuplicateForIdentityDocument(int $documentId, string $sha256): ?AdmissionDocumentFile
    {
        return AdmissionDocumentFile::query()
            ->active()
            ->where('identity_document_id', $documentId)
            ->where('sha256', $sha256)
            ->first();
    }

    public function activeDuplicateForEducationDocument(int $documentId, string $sha256): ?AdmissionDocumentFile
    {
        return AdmissionDocumentFile::query()
            ->active()
            ->where('education_document_id', $documentId)
            ->where('sha256', $sha256)
            ->first();
    }
}
