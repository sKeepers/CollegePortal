<?php

namespace App\Services\Admissions;

use App\Models\Admissions\AdmissionApplication;
use App\Models\Admissions\EducationDocument;
use App\Models\Admissions\IdentityDocument;
use App\Repositories\Admissions\AdmissionApplicationRepository;

class AdmissionDocumentReadinessService
{
    public function __construct(
        private readonly AdmissionApplicationRepository $applications,
        private readonly FisAdmissionDocumentReadinessService $fisReadiness,
        private readonly DocumentMaskingService $masking,
    ) {
    }

    /** @return array<string, mixed> */
    public function forApplication(int $applicationId): array
    {
        $application = $this->applications->find($applicationId);
        abort_if(! $application, 404);
        $application->loadMissing('applicant.person');

        $identity = $this->identityDocument($application);
        $education = $this->educationDocument($application);
        $person = $application->applicant?->person;
        $identityFiles = $identity?->activeFiles()->count() ?? 0;
        $educationFiles = $education?->activeFiles()->count() ?? 0;

        $blocking = [];
        if (! $identity) {
            $blocking[] = 'Нет действующего документа, удостоверяющего личность.';
        }
        if (! filled($person?->snils)) {
            $blocking[] = 'Не заполнен СНИЛС Person.';
        }
        if (! $education) {
            $blocking[] = 'Нет действующего документа об образовании.';
        }
        if ($identity && $identityFiles === 0) {
            $blocking[] = 'Нет файла-образа документа личности.';
        }
        if ($education && $educationFiles === 0) {
            $blocking[] = 'Нет файла-образа документа об образовании.';
        }

        $reviewBlocking = [];
        if ($identity && $identity->verification_status !== IdentityDocument::STATUS_VERIFIED) {
            $reviewBlocking[] = 'Документ личности не проверен.';
        }
        if ($education && $education->verification_status !== EducationDocument::STATUS_VERIFIED) {
            $reviewBlocking[] = 'Документ об образовании не проверен.';
        }

        $fis = $this->fisReadiness->assess($application, $identity, $education);

        return [
            'application_id' => $application->id,
            'applicant_id' => $application->applicant_id,
            'identity_document' => $this->component($identity !== null, $identity?->verification_status, $identityFiles),
            'snils' => [
                'status' => filled($person?->snils) ? 'complete' : 'missing',
                'masked' => $this->masking->snils($person?->snils),
                'required' => true,
            ],
            'education_document' => $this->component($education !== null, $education?->verification_status, $educationFiles),
            'files' => [
                'identity_files_count' => $identityFiles,
                'education_files_count' => $educationFiles,
                'status' => ($identityFiles > 0 && $educationFiles > 0) ? 'complete' : 'incomplete',
            ],
            'internal_complete' => $blocking === [],
            'review_complete' => $blocking === [] && $reviewBlocking === [],
            'fis_data_ready' => $fis['fis_data_ready'],
            'blocking_reasons' => array_values(array_unique($blocking)),
            'review_blocking_reasons' => array_values(array_unique($reviewBlocking)),
            'fis' => $fis,
        ];
    }

    private function identityDocument(AdmissionApplication $application): ?IdentityDocument
    {
        return IdentityDocument::query()
            ->with('activeFiles')
            ->active()
            ->where('applicant_id', $application->applicant_id)
            ->whereIn('verification_status', IdentityDocument::ACTIVE_STATUSES)
            ->orderByDesc('is_primary')
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->first();
    }

    private function educationDocument(AdmissionApplication $application): ?EducationDocument
    {
        return EducationDocument::query()
            ->with('activeFiles')
            ->active()
            ->where('applicant_id', $application->applicant_id)
            ->whereIn('verification_status', EducationDocument::ACTIVE_STATUSES)
            ->orderByDesc('is_primary')
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->first();
    }

    /** @return array<string, mixed> */
    private function component(bool $exists, ?string $verificationStatus, int $filesCount): array
    {
        return [
            'status' => $exists ? 'present' : 'missing',
            'verification_status' => $verificationStatus,
            'files_count' => $filesCount,
        ];
    }
}
