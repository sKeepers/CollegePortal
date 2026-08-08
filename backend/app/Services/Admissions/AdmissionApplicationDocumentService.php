<?php

namespace App\Services\Admissions;

use App\Models\Admissions\AdmissionApplication;
use App\Models\Admissions\ApplicationDocumentSet;
use App\Models\Admissions\EducationDocument;
use App\Models\Admissions\IdentityDocument;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdmissionApplicationDocumentService
{
    public function __construct(private readonly PersonDocumentService $personDocuments)
    {
    }

    public function show(int $applicationId): ApplicationDocumentSet
    {
        $application = $this->foundationApplication($applicationId);

        return $this->readDocumentSet($application);
    }

    public function assignIdentity(int $applicationId, int $documentId, ?User $actor = null): ApplicationDocumentSet
    {
        return DB::transaction(function () use ($applicationId, $documentId, $actor): ApplicationDocumentSet {
            $application = $this->foundationApplication($applicationId);
            $document = IdentityDocument::query()->current()->find($documentId);

            if (! $document || $document->person_id !== $application->applicant?->person_id) {
                throw ValidationException::withMessages(['document_id' => 'Документ личности не найден или не относится к человеку из заявления.']);
            }

            $set = $this->writableDocumentSet($application);
            $this->assertCanReplaceRegisteredDocument($application, $set->identity_document_id, $document->id, 'identity_document_id');

            $old = $this->snapshot($set);
            $set->update([
                'identity_document_id' => $document->id,
                'linked_by' => $actor?->id,
                'linked_at' => now(),
            ]);
            $set->refresh()->load(['identityDocument.activeFiles', 'educationDocument.activeFiles']);

            AuditLogService::log('Admissions', 'admission_application_identity_document_linked', $application, $old, $this->snapshot($set), user: $actor);

            return $set;
        });
    }

    public function assignEducation(int $applicationId, int $documentId, ?User $actor = null): ApplicationDocumentSet
    {
        return DB::transaction(function () use ($applicationId, $documentId, $actor): ApplicationDocumentSet {
            $application = $this->foundationApplication($applicationId);
            $document = EducationDocument::query()->current()->find($documentId);

            if (! $document || $document->person_id !== $application->applicant?->person_id) {
                throw ValidationException::withMessages(['document_id' => 'Документ об образовании не найден или не относится к человеку из заявления.']);
            }

            $set = $this->writableDocumentSet($application);
            $this->assertCanReplaceRegisteredDocument($application, $set->education_document_id, $document->id, 'education_document_id');

            $old = $this->snapshot($set);
            $set->update([
                'education_document_id' => $document->id,
                'linked_by' => $actor?->id,
                'linked_at' => now(),
            ]);
            $set->refresh()->load(['identityDocument.activeFiles', 'educationDocument.activeFiles']);

            AuditLogService::log('Admissions', 'admission_application_education_document_linked', $application, $old, $this->snapshot($set), user: $actor);

            return $set;
        });
    }

    public function ensureForRegistration(AdmissionApplication $application, ?User $actor = null): ApplicationDocumentSet
    {
        $application = $this->foundationApplication($application->id);
        $set = $this->writableDocumentSet($application);
        $old = $this->snapshot($set);
        $changes = [];

        if ($set->identity_document_id === null) {
            $changes['identity_document_id'] = $this->currentIdentityDocument($application)?->id;
        }

        if ($set->education_document_id === null) {
            $changes['education_document_id'] = $this->currentEducationDocument($application)?->id;
        }

        if ($changes !== []) {
            $set->update([
                ...$changes,
                'linked_by' => $actor?->id,
                'linked_at' => now(),
            ]);
            $set->refresh()->load(['identityDocument.activeFiles', 'educationDocument.activeFiles']);

            AuditLogService::log('Admissions', 'admission_application_documents_fixed_for_registration', $application, $old, $this->snapshot($set), user: $actor);
        }

        return $set;
    }

    /** @return array{identity:?IdentityDocument,education:?EducationDocument,set:ApplicationDocumentSet} */
    public function documentsForReadiness(AdmissionApplication $application): array
    {
        $application = $this->foundationApplication($application->id);
        $set = $this->readDocumentSet($application);

        $identity = $set->identityDocument ?: $this->currentIdentityDocument($application);
        $education = $set->educationDocument ?: $this->currentEducationDocument($application);

        return [
            'identity' => $identity?->loadMissing('activeFiles', 'documentType', 'releaseCountry'),
            'education' => $education?->loadMissing('activeFiles', 'documentType', 'country', 'educationLevel'),
            'set' => $set,
        ];
    }

    public function isIdentityLinkedToRegisteredApplication(IdentityDocument $document): bool
    {
        return ApplicationDocumentSet::query()
            ->where('identity_document_id', $document->id)
            ->whereHas('application', fn ($query) => $query->foundation()->where('status', AdmissionApplication::STATUS_REGISTERED))
            ->exists();
    }

    public function isEducationLinkedToRegisteredApplication(EducationDocument $document): bool
    {
        return ApplicationDocumentSet::query()
            ->where('education_document_id', $document->id)
            ->whereHas('application', fn ($query) => $query->foundation()->where('status', AdmissionApplication::STATUS_REGISTERED))
            ->exists();
    }

    private function foundationApplication(int $applicationId): AdmissionApplication
    {
        $application = AdmissionApplication::query()
            ->foundation()
            ->with(['applicant', 'documentSet.identityDocument.activeFiles', 'documentSet.educationDocument.activeFiles'])
            ->find($applicationId);

        if (! $application) {
            abort(404, 'Foundation-заявление не найдено.');
        }

        return $application;
    }

    private function readDocumentSet(AdmissionApplication $application): ApplicationDocumentSet
    {
        $set = $application->documentSet;

        if (! $set) {
            $set = new ApplicationDocumentSet(['application_id' => $application->id]);
            $set->exists = false;
        }

        return $set->load(['identityDocument.activeFiles', 'educationDocument.activeFiles']);
    }

    private function writableDocumentSet(AdmissionApplication $application): ApplicationDocumentSet
    {
        return ApplicationDocumentSet::query()->firstOrCreate(
            ['application_id' => $application->id],
            ['linked_at' => null],
        )->load(['identityDocument.activeFiles', 'educationDocument.activeFiles']);
    }

    private function currentIdentityDocument(AdmissionApplication $application): ?IdentityDocument
    {
        return $this->personDocuments->currentIdentity($application->applicant?->person_id);
    }

    private function currentEducationDocument(AdmissionApplication $application): ?EducationDocument
    {
        return $this->personDocuments->currentEducation($application->applicant?->person_id);
    }

    private function assertCanReplaceRegisteredDocument(AdmissionApplication $application, ?int $currentId, int $newId, string $field): void
    {
        if ($application->isRegistered() && $currentId !== null && $currentId !== $newId) {
            throw ValidationException::withMessages([$field => 'Документы зарегистрированного заявления уже зафиксированы.']);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(ApplicationDocumentSet $set): array
    {
        return [
            'application_id' => $set->application_id,
            'identity_document_id' => $set->identity_document_id,
            'education_document_id' => $set->education_document_id,
            'linked_at' => $set->linked_at?->toISOString(),
        ];
    }
}
