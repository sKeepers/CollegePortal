<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EnrollApplicantApplicationRequest;
use App\Http\Requests\StoreApplicantApplicationRequest;
use App\Http\Requests\UpdateApplicantApplicationDocumentRequest;
use App\Http\Requests\UpdateApplicantApplicationRequest;
use App\Http\Resources\ApplicantApplicationResource;
use App\Http\Resources\StudentResource;
use App\Models\ApplicantApplication;
use App\Models\ApplicantApplicationDocument;
use App\Models\Student;
use App\Services\ApplicantApplicationCsvService;
use App\Services\ApplicantApplicationDocumentService;
use App\Services\ApplicantApplicationEventService;
use App\Services\Bulk\BulkSelectionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApplicantApplicationController extends Controller
{
    public function __construct(
        private readonly ApplicantApplicationCsvService $applicantApplicationCsvService,
        private readonly ApplicantApplicationEventService $eventService,
        private readonly ApplicantApplicationDocumentService $documentService,
        private readonly BulkSelectionResolver $selectionResolver,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->selectionResolver
            ->applyAdmissionSelection(
                ApplicantApplication::query()->legacy()->with(['educationProgram.specialty', 'events', 'documents']),
                ['filter' => $this->filterFromRequest($request)]
            )
            ->when($request->string('education_base')->toString(), fn ($query, string $base) => $query->where('education_base', $base))
            ->orderByDesc('submitted_at')
            ->orderBy('last_name');

        $perPage = $request->integer('per_page', $request->integer('rowsPerPage', 50));
        $perPage = max(0, min($perPage, 1000));

        if ($perPage === 0) {
            $applications = $query->get();
            $applications->each(function (ApplicantApplication $application): void {
                $this->documentService->ensureDefaultDocuments($application);
                $application->load('documents');
            });

            return ApplicantApplicationResource::collection($applications);
        }

        $applications = $query->paginate($perPage ?: 50);

        $applications->getCollection()->each(function (ApplicantApplication $application): void {
            $this->documentService->ensureDefaultDocuments($application);
            $application->load('documents');
        });

        return ApplicantApplicationResource::collection($applications);
    }


    public function stats(Request $request): JsonResponse
    {
        $filter = $this->filterFromRequest($request);
        $base = fn () => $this->selectionResolver->applyAdmissionSelection(ApplicantApplication::query()->legacy(), ['filter' => $filter]);

        $requiredDocumentsCount = ApplicantApplicationDocumentService::REQUIRED_DOCUMENTS_COUNT;

        $stats = [
            'total' => (clone $base())->count(),
            'new' => (clone $base())->where('status', 'new')->count(),
            'no_documents' => (clone $base())->whereDoesntHave('documents', fn ($query) => $query->whereIn('status', ApplicantApplicationDocument::COMPLETE_STATUSES))->count(),
            'incomplete' => (clone $base())
                ->whereHas('documents', fn ($query) => $query->whereIn('status', ApplicantApplicationDocument::COMPLETE_STATUSES), '>=', 1)
                ->whereHas('documents', fn ($query) => $query->whereIn('status', ApplicantApplicationDocument::COMPLETE_STATUSES), '<', $requiredDocumentsCount)
                ->count(),
            'complete' => (clone $base())->whereHas('documents', fn ($query) => $query->whereIn('status', ApplicantApplicationDocument::COMPLETE_STATUSES), '>=', $requiredDocumentsCount)->count(),
            'documents_provided' => (clone $base())
                ->where(fn ($query) => $query
                    ->where('documents_provided', true)
                    ->orWhereHas('documents', fn ($query) => $query->whereIn('status', ApplicantApplicationDocument::COMPLETE_STATUSES)))
                ->count(),
            'ready' => (clone $base())->where('status', 'ready_for_enrollment')->count(),
            'recommended' => (clone $base())->where('recommended_for_enrollment', true)->count(),
            'enrolled' => (clone $base())->where('status', 'enrolled')->count(),
            'rejected' => (clone $base())->where('status', 'rejected')->count(),
        ];

        return response()->json(['data' => $stats]);
    }

    private function filterFromRequest(Request $request): array
    {
        return [
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'specialtyId' => $request->query('specialtyId', $request->query('specialty_id')),
            'educationProgramId' => $request->query('educationProgramId', $request->query('education_program_id')),
            'documents_status' => $request->query('documents_status', $request->query('documentsStatus', $request->query('completeness'))),
            'completeness' => $request->query('completeness'),
            'submittedDate' => $request->query('submittedDate', $request->query('submitted_at')),
        ];
    }

    public function store(StoreApplicantApplicationRequest $request): JsonResponse
    {
        $application = ApplicantApplication::create($request->validated());
        $this->documentService->ensureDefaultDocuments($application);
        $this->eventService->record($application, 'created', 'Создано заявление', 'Заявление добавлено вручную.');

        return (new ApplicantApplicationResource($application->load(['educationProgram.specialty', 'events', 'documents'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(ApplicantApplication $applicantApplication): ApplicantApplicationResource
    {
        $this->abortIfFoundationRecord($applicantApplication);
        $this->documentService->ensureDefaultDocuments($applicantApplication);

        return new ApplicantApplicationResource($applicantApplication->load(['educationProgram.specialty', 'events', 'documents']));
    }

    public function update(UpdateApplicantApplicationRequest $request, ApplicantApplication $applicantApplication): ApplicantApplicationResource
    {
        $this->abortIfFoundationRecord($applicantApplication);
        $oldStatus = $applicantApplication->status;
        $oldComment = $applicantApplication->comment;
        $applicantApplication->update($request->validated());

        if ($applicantApplication->wasChanged('status')) {
            $this->eventService->record(
                $applicantApplication,
                'status_changed',
                'Изменен статус',
                "Статус изменен с {$this->statusLabel($oldStatus)} на {$this->statusLabel($applicantApplication->status)}.",
                ['from' => $oldStatus, 'to' => $applicantApplication->status],
            );
        }

        if ($applicantApplication->wasChanged('comment') && $oldComment !== $applicantApplication->comment) {
            $this->eventService->record($applicantApplication, 'comment_changed', 'Изменен комментарий', $applicantApplication->comment);
        }

        return new ApplicantApplicationResource($applicantApplication->load(['educationProgram.specialty', 'events', 'documents']));
    }

    public function destroy(ApplicantApplication $applicantApplication): Response
    {
        $this->abortIfFoundationRecord($applicantApplication);
        $applicantApplication->delete();

        return response()->noContent();
    }

    public function export(Request $request): StreamedResponse
    {
        return $this->applicantApplicationCsvService->export($request);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        try {
            $summary = $this->applicantApplicationCsvService->import($request->file('file'));
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'message' => 'Импорт заявлений абитуриентов завершен.',
            'data' => $summary,
        ]);
    }

    public function enroll(EnrollApplicantApplicationRequest $request, ApplicantApplication $applicantApplication): JsonResponse
    {
        $this->abortIfFoundationRecord($applicantApplication);
        $this->documentService->ensureDefaultDocuments($applicantApplication);

        $documentsTotal = $applicantApplication->documents()->count();
        $documentsReceived = $applicantApplication->documents()->where('is_received', true)->count();

        if ($documentsTotal === 0 || $documentsReceived < $documentsTotal) {
            throw ValidationException::withMessages([
                'documents' => ['Нельзя зачислить: получены не все обязательные документы.'],
            ]);
        }

        if ($applicantApplication->email && Student::where('email', $applicantApplication->email)->exists()) {
            throw ValidationException::withMessages([
                'email' => ['Студент с таким email уже существует.'],
            ]);
        }

        $student = DB::transaction(function () use ($request, $applicantApplication): Student {
            $student = Student::create([
                'group_id' => $request->integer('group_id'),
                'last_name' => $applicantApplication->last_name,
                'first_name' => $applicantApplication->first_name,
                'middle_name' => $applicantApplication->middle_name,
                'birth_date' => $applicantApplication->birth_date,
                'phone' => $applicantApplication->phone,
                'email' => $applicantApplication->email,
                'status' => 'active',
                'enrollment_date' => $request->date('enrollment_date')->toDateString(),
            ]);

            $applicantApplication->update(['status' => 'enrolled']);
            $this->eventService->record(
                $applicantApplication,
                'enrolled',
                'Абитуриент зачислен',
                "Создана карточка студента в группе {$student->group_id}.",
                ['student_id' => $student->id, 'group_id' => $student->group_id],
            );

            return $student;
        });

        return response()->json([
            'message' => 'Абитуриент зачислен в студенты.',
            'data' => [
                'application' => new ApplicantApplicationResource($applicantApplication->fresh()->load(['educationProgram.specialty', 'events', 'documents'])),
                'student' => new StudentResource($student->load('group')),
            ],
        ], Response::HTTP_CREATED);
    }

    public function updateDocument(
        UpdateApplicantApplicationDocumentRequest $request,
        ApplicantApplication $applicantApplication,
        string $type,
    ): ApplicantApplicationResource {
        $this->abortIfFoundationRecord($applicantApplication);
        $document = $this->documentService->updateDocument($applicantApplication, $type, $request->validated());
        $this->eventService->record(
            $applicantApplication,
            'document_updated',
            $document->is_received ? 'Документ получен' : 'Документ отмечен как неполученный',
            $document->title,
            ['document_type' => $document->type, 'document_id' => $document->id],
        );

        return new ApplicantApplicationResource($applicantApplication->fresh()->load(['educationProgram.specialty', 'events', 'documents']));
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'new' => 'Новое',
            'accepted' => 'Принято',
            'needs_clarification' => 'Требуется уточнение',
            'rejected' => 'Отклонено',
            'enrolled' => 'Зачислен',
            default => $status,
        };
    }

    private function abortIfFoundationRecord(ApplicantApplication $applicantApplication): void
    {
        abort_if($applicantApplication->isFoundationRecord(), Response::HTTP_NOT_FOUND);
    }
}
