<?php

namespace App\Http\Controllers\Api\Admissions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admissions\StoreIdentityDocumentRequest;
use App\Http\Requests\Admissions\UpdateIdentityDocumentRequest;
use App\Http\Resources\Admissions\IdentityDocumentResource;
use App\Services\Admissions\IdentityDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class IdentityDocumentController extends Controller
{
    public function __construct(private readonly IdentityDocumentService $documents)
    {
    }

    public function index(Request $request, int $applicant): AnonymousResourceCollection
    {
        $filters = $request->validate(['with_archived' => ['nullable', 'boolean']]);

        return IdentityDocumentResource::collection(
            $this->documents->listForApplicant($applicant, filter_var($filters['with_archived'] ?? false, FILTER_VALIDATE_BOOLEAN))
        );
    }

    public function store(StoreIdentityDocumentRequest $request, int $applicant): JsonResponse
    {
        $document = $this->documents->create($applicant, $request->validated(), $request->user());

        return (new IdentityDocumentResource($document))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(int $document): IdentityDocumentResource
    {
        $model = $this->documents->find($document);
        abort_if(! $model, Response::HTTP_NOT_FOUND);

        return new IdentityDocumentResource($model);
    }

    public function update(UpdateIdentityDocumentRequest $request, int $document): IdentityDocumentResource
    {
        return new IdentityDocumentResource($this->documents->update($document, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, int $document): Response
    {
        $this->documents->archive($document, $request->user());

        return response()->noContent();
    }
}
