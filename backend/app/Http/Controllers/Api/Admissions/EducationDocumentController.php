<?php

namespace App\Http\Controllers\Api\Admissions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admissions\StoreEducationDocumentRequest;
use App\Http\Requests\Admissions\UpdateEducationDocumentRequest;
use App\Http\Resources\Admissions\EducationDocumentResource;
use App\Services\Admissions\EducationDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class EducationDocumentController extends Controller
{
    public function __construct(private readonly EducationDocumentService $documents)
    {
    }

    public function index(Request $request, int $applicant): AnonymousResourceCollection
    {
        $filters = $request->validate(['with_archived' => ['nullable', 'boolean']]);

        return EducationDocumentResource::collection(
            $this->documents->listForApplicant($applicant, filter_var($filters['with_archived'] ?? false, FILTER_VALIDATE_BOOLEAN))
        );
    }

    public function store(StoreEducationDocumentRequest $request, int $applicant): JsonResponse
    {
        $document = $this->documents->create($applicant, $request->validated(), $request->user());

        return (new EducationDocumentResource($document))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(int $document): EducationDocumentResource
    {
        $model = $this->documents->find($document);
        abort_if(! $model, Response::HTTP_NOT_FOUND);

        return new EducationDocumentResource($model);
    }

    public function update(UpdateEducationDocumentRequest $request, int $document): EducationDocumentResource
    {
        return new EducationDocumentResource($this->documents->update($document, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, int $document): Response
    {
        $this->documents->archive($document, $request->user());

        return response()->noContent();
    }
}
