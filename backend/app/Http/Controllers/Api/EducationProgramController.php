<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEducationProgramRequest;
use App\Http\Requests\UpdateEducationProgramRequest;
use App\Http\Resources\EducationProgramResource;
use App\Models\EducationProgram;
use App\Services\EducationProgramCsvService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EducationProgramController extends Controller
{
    public function __construct(private readonly EducationProgramCsvService $educationProgramCsvService)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $programs = EducationProgram::query()
            ->with('specialty')
            ->when($request->integer('specialty_id'), fn ($query, int $specialtyId) => $query->where('specialty_id', $specialtyId))
            ->when($request->boolean('active_only'), fn ($query) => $query->where('is_active', true))
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $search = mb_strtolower($search);

                $query->where(function ($query) use ($search): void {
                    $query
                        ->whereRaw('lower(name) like ?', ["%{$search}%"])
                        ->orWhereRaw('lower(study_form) like ?', ["%{$search}%"]);
                });
            })
            ->orderByDesc('year_start')
            ->orderBy('name')
            ->paginate(50);

        return EducationProgramResource::collection($programs);
    }

    public function store(StoreEducationProgramRequest $request): JsonResponse
    {
        $program = EducationProgram::create($request->validated());

        return (new EducationProgramResource($program->load('specialty')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(EducationProgram $educationProgram): EducationProgramResource
    {
        return new EducationProgramResource($educationProgram->load('specialty'));
    }

    public function update(UpdateEducationProgramRequest $request, EducationProgram $educationProgram): EducationProgramResource
    {
        $educationProgram->update($request->validated());

        return new EducationProgramResource($educationProgram->load('specialty'));
    }

    public function destroy(EducationProgram $educationProgram): Response
    {
        $educationProgram->delete();

        return response()->noContent();
    }

    public function export(): StreamedResponse
    {
        return $this->educationProgramCsvService->export();
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $summary = $this->educationProgramCsvService->import($request->file('file'));

        return response()->json(['data' => $summary]);
    }
}
