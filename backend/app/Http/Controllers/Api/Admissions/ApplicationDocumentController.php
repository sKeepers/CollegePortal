<?php

namespace App\Http\Controllers\Api\Admissions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admissions\AssignApplicationDocumentRequest;
use App\Http\Resources\Admissions\ApplicationDocumentSetResource;
use App\Services\Admissions\AdmissionApplicationDocumentService;

class ApplicationDocumentController extends Controller
{
    public function __construct(private readonly AdmissionApplicationDocumentService $documents)
    {
    }

    public function show(int $application): ApplicationDocumentSetResource
    {
        return new ApplicationDocumentSetResource($this->documents->show($application));
    }

    public function assignIdentity(AssignApplicationDocumentRequest $request, int $application): ApplicationDocumentSetResource
    {
        return new ApplicationDocumentSetResource(
            $this->documents->assignIdentity($application, (int) $request->validated()['document_id'], $request->user())
        );
    }

    public function assignEducation(AssignApplicationDocumentRequest $request, int $application): ApplicationDocumentSetResource
    {
        return new ApplicationDocumentSetResource(
            $this->documents->assignEducation($application, (int) $request->validated()['document_id'], $request->user())
        );
    }
}
