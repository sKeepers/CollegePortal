<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStudentRequest;
use App\Http\Requests\UpdateStudentRequest;
use App\Http\Resources\StudentResource;
use App\Models\Student;
use App\Services\StudentCsvService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentController extends Controller
{
    public function __construct(private readonly StudentCsvService $studentCsvService)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $students = Student::query()
            ->with('group')
            ->when($request->integer('group_id'), fn ($query, int $groupId) => $query->where('group_id', $groupId))
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $operator = $query->getModel()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

                $query->where(function ($query) use ($operator, $search): void {
                    $query
                        ->where('last_name', $operator, "%{$search}%")
                        ->orWhere('first_name', $operator, "%{$search}%")
                        ->orWhere('middle_name', $operator, "%{$search}%");
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(20);

        return StudentResource::collection($students);
    }

    public function store(StoreStudentRequest $request): JsonResponse
    {
        $student = Student::create($request->validated());

        return (new StudentResource($student->load('group')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Student $student): StudentResource
    {
        return new StudentResource($student->load('group'));
    }

    public function update(UpdateStudentRequest $request, Student $student): StudentResource
    {
        $student->update($request->validated());

        return new StudentResource($student->load('group'));
    }

    public function destroy(Student $student): Response
    {
        $student->delete();

        return response()->noContent();
    }

    public function export(): StreamedResponse
    {
        return $this->studentCsvService->export();
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        try {
            $summary = $this->studentCsvService->import($request->file('file'));
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'message' => 'Импорт студентов завершен.',
            'data' => $summary,
        ]);
    }
}
