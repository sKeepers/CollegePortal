<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admissions\StoreEducationDocumentRequest;
use App\Http\Requests\Admissions\StoreIdentityDocumentRequest;
use App\Http\Resources\Admissions\EducationDocumentResource;
use App\Http\Resources\Admissions\IdentityDocumentResource;
use App\Models\Student;
use App\Services\Admissions\AdmissionDocumentReadinessService;
use App\Services\Admissions\EducationDocumentService;
use App\Services\Admissions\IdentityDocumentService;
use App\Services\StudentPersonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Документы студента и полнота его карточки.
 *
 * Паспорт и документ об образовании принадлежат человеку, поэтому студент, не проходивший
 * приёмную комиссию, заводит их здесь: заявления у него нет, а документы быть обязаны.
 */
class StudentDocumentController extends Controller
{
    private const SUMMARY_CACHE_KEY = 'students.card-completeness.summary';

    private const SUMMARY_CACHE_TTL = 600;

    public function __construct(
        private readonly AdmissionDocumentReadinessService $readiness,
        private readonly IdentityDocumentService $identityDocuments,
        private readonly EducationDocumentService $educationDocuments,
        private readonly StudentPersonService $studentPeople,
    ) {
    }

    public function completeness(Student $student): JsonResponse
    {
        return response()->json(['data' => $this->readiness->forStudent($student)]);
    }

    public function summary(): JsonResponse
    {
        $summary = Cache::remember(
            self::SUMMARY_CACHE_KEY,
            self::SUMMARY_CACHE_TTL,
            fn (): array => $this->readiness->studentCardSummary(),
        );

        return response()->json(['data' => $summary]);
    }

    public function index(Student $student): JsonResponse
    {
        $personId = $student->person_id;

        return response()->json([
            'data' => [
                'person_id' => $personId,
                'identity_documents' => IdentityDocumentResource::collection(
                    $personId ? $this->identityDocuments->listForPerson((int) $personId) : collect(),
                ),
                'education_documents' => EducationDocumentResource::collection(
                    $personId ? $this->educationDocuments->listForPerson((int) $personId) : collect(),
                ),
                'completeness' => $this->readiness->forStudent($student),
            ],
        ]);
    }

    public function storeIdentity(StoreIdentityDocumentRequest $request, Student $student): JsonResponse
    {
        $resolved = $this->studentPeople->ensureForStudent($student);
        $document = $this->identityDocuments->createForPerson($resolved['person']->id, $request->validated(), $request->user());
        $this->forgetSummary();

        return (new IdentityDocumentResource($document))
            ->additional(['warnings' => ['duplicate_candidates' => $resolved['duplicate_candidates']]])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function storeEducation(StoreEducationDocumentRequest $request, Student $student): JsonResponse
    {
        $resolved = $this->studentPeople->ensureForStudent($student);
        $document = $this->educationDocuments->createForPerson($resolved['person']->id, $request->validated(), $request->user());
        $this->forgetSummary();

        return (new EducationDocumentResource($document))
            ->additional(['warnings' => ['duplicate_candidates' => $resolved['duplicate_candidates']]])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    private function forgetSummary(): void
    {
        Cache::forget(self::SUMMARY_CACHE_KEY);
    }
}
