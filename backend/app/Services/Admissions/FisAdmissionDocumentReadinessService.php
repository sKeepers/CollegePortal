<?php

namespace App\Services\Admissions;

use App\Models\Admissions\EducationDocument;
use App\Models\Admissions\IdentityDocument;
use App\Models\Admissions\AdmissionApplication;

class FisAdmissionDocumentReadinessService
{
    /**
     * Возвращает readiness для будущего FIS DTO. XML, SOAP и отправка здесь намеренно отсутствуют.
     *
     * @return array<string, mixed>
     */
    public function assess(AdmissionApplication $application, ?IdentityDocument $identity, ?EducationDocument $education): array
    {
        $missing = [];

        if (! $identity) {
            $missing[] = 'identity_document';
        } else {
            foreach ([
                'fis_uid' => $identity->fis_uid,
                'fis_identity_document_type_id' => $identity->fis_identity_document_type_id,
                'fis_nationality_type_id' => $identity->fis_nationality_type_id,
                'fis_release_country_id' => $identity->fis_release_country_id,
                'number' => $identity->number,
                'issue_date' => $identity->issue_date,
                'release_place' => $identity->release_place,
            ] as $field => $value) {
                if (! filled($value)) {
                    $missing[] = 'identity_document.'.$field;
                }
            }
        }

        if (! $education) {
            $missing[] = 'education_document';
        } else {
            foreach ([
                'fis_uid' => $education->fis_uid,
                'fis_document_type_id' => $education->fis_document_type_id,
                'number' => $education->number,
                'issue_date' => $education->issue_date,
                'document_organization' => $education->document_organization,
            ] as $field => $value) {
                if (! filled($value)) {
                    $missing[] = 'education_document.'.$field;
                }
            }
        }

        if (! filled($application->applicant?->person?->snils)) {
            $missing[] = 'person.snils';
        }

        return [
            'fis_data_ready' => false,
            'fis_mapping_ready' => false,
            'supported_xsd_structures' => [
                'TIdentityDocument',
                'TSchoolCertificateDocument',
                'TSchoolGeneralCertificateDocument',
                'TMiddleEduDiplomaDocument',
                'TBasicDiplomaDocument',
                'TForeignStateEduDocument',
                'TAcademicDiplomaDocument',
                'TEduCustomDocument',
            ],
            'missing_mappings' => array_values(array_unique($missing)),
            'blocking_reasons' => array_values(array_unique([
                ...$missing,
                'fis_xml_package_generation_is_out_of_scope_for_BACK_005',
            ])),
        ];
    }
}
