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
        private readonly AdmissionApplicationDocumentService $applicationDocuments,
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

        $documents = $this->applicationDocuments->documentsForReadiness($application);
        $identity = $documents['identity'];
        $education = $documents['education'];
        $documentSet = $documents['set'];
        $person = $application->applicant?->person;
        $identityFiles = $identity?->activeFiles()->count() ?? 0;
        $educationFiles = $education?->activeFiles()->count() ?? 0;

        $blocking = [];
        $blockingDetailed = [];
        if (! $identity) {
            $blocking[] = 'Нет действующего документа, удостоверяющего личность.';
            $blockingDetailed[] = $this->reason('identity_document_missing', 'identity_document', 'Нет действующего документа, удостоверяющего личность.');
        }
        if (! filled($person?->snils)) {
            $blocking[] = 'Не заполнен СНИЛС Person.';
            $blockingDetailed[] = $this->reason('person_snils_missing', 'person.snils', 'Не заполнен СНИЛС Person.');
        }
        if (! $education) {
            $blocking[] = 'Нет действующего документа об образовании.';
            $blockingDetailed[] = $this->reason('education_document_missing', 'education_document', 'Нет действующего документа об образовании.');
        }
        if ($identity && $identityFiles === 0) {
            $blocking[] = 'Нет файла-образа документа личности.';
            $blockingDetailed[] = $this->reason('identity_document_file_missing', 'identity_document.files', 'Нет файла-образа документа личности.');
        }
        if ($education && $educationFiles === 0) {
            $blocking[] = 'Нет файла-образа документа об образовании.';
            $blockingDetailed[] = $this->reason('education_document_file_missing', 'education_document.files', 'Нет файла-образа документа об образовании.');
        }

        $reviewBlocking = [];
        $reviewBlockingDetailed = [];
        if ($identity && $identity->verification_status !== IdentityDocument::STATUS_VERIFIED) {
            $reviewBlocking[] = 'Документ личности не проверен.';
            $reviewBlockingDetailed[] = $this->reason('identity_document_not_verified', 'identity_document.verification_status', 'Документ личности не проверен.');
        }
        if ($education && $education->verification_status !== EducationDocument::STATUS_VERIFIED) {
            $reviewBlocking[] = 'Документ об образовании не проверен.';
            $reviewBlockingDetailed[] = $this->reason('education_document_not_verified', 'education_document.verification_status', 'Документ об образовании не проверен.');
        }

        $fis = $this->fisReadiness->assess($application, $identity, $education);

        return [
            'application_id' => $application->id,
            'applicant_id' => $application->applicant_id,
            'document_set_id' => $documentSet->id,
            'linked_identity_document_id' => $documentSet->identity_document_id,
            'linked_education_document_id' => $documentSet->education_document_id,
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
            'blocking_reasons_detailed' => $blockingDetailed,
            'review_blocking_reasons' => array_values(array_unique($reviewBlocking)),
            'review_blocking_reasons_detailed' => $reviewBlockingDetailed,
            'fis' => $fis,
        ];
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

    /** @return array{code:string,field:string,message:string} */
    private function reason(string $code, string $field, string $message): array
    {
        return ['code' => $code, 'field' => $field, 'message' => $message];
    }
}
