<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClassroomRequest;
use App\Http\Requests\UpdateClassroomRequest;
use App\Http\Resources\ClassroomResource;
use App\Models\Classroom;
use App\Services\ClassroomCsvService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClassroomController extends Controller
{
    public function __construct(private readonly ClassroomCsvService $classroomCsvService)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $classrooms = Classroom::query()
            ->when($request->string('building')->toString(), fn ($query, string $building) => $query->where('building', $building))
            ->when($request->string('type')->toString(), fn ($query, string $type) => $query->where('type', $type))
            ->orderBy('building')
            ->orderBy('number')
            ->paginate(20);

        return ClassroomResource::collection($classrooms);
    }

    public function store(StoreClassroomRequest $request): JsonResponse
    {
        $classroom = Classroom::create($request->validated());

        return (new ClassroomResource($classroom))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Classroom $classroom): ClassroomResource
    {
        return new ClassroomResource($classroom);
    }

    public function update(UpdateClassroomRequest $request, Classroom $classroom): ClassroomResource
    {
        $classroom->update($request->validated());

        return new ClassroomResource($classroom);
    }

    public function destroy(Classroom $classroom): Response
    {
        $classroom->delete();

        return response()->noContent();
    }

    public function export(): StreamedResponse
    {
        return $this->classroomCsvService->export();
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $summary = $this->classroomCsvService->import($request->file('file'));

        return response()->json(['data' => $summary]);
    }
}
