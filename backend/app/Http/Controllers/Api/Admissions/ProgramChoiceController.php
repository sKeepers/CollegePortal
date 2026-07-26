<?php

namespace App\Http\Controllers\Api\Admissions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admissions\StoreProgramChoiceRequest;
use App\Http\Requests\Admissions\UpdateProgramChoiceRequest;
use App\Http\Resources\Admissions\ProgramChoiceResource;
use App\Services\Admissions\ProgramChoiceService;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class ProgramChoiceController extends Controller
{
    public function __construct(private readonly ProgramChoiceService $choices)
    {
    }

    public function index(Request $request, int $application): AnonymousResourceCollection
    {
        AuditLogService::log('Admissions', 'program_choice_index', ['type' => 'AdmissionApplication', 'id' => $application], null, [
            'application_id' => $application,
        ], user: $request->user());

        return ProgramChoiceResource::collection($this->choices->listForApplication($application));
    }

    public function store(StoreProgramChoiceRequest $request, int $application): JsonResponse
    {
        $choice = $this->choices->create($application, $request->validated(), $request->user());

        return (new ProgramChoiceResource($choice))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateProgramChoiceRequest $request, int $choice): ProgramChoiceResource
    {
        return new ProgramChoiceResource($this->choices->update($choice, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, int $choice): Response
    {
        $this->choices->delete($choice, $request->user());

        return response()->noContent();
    }
}
