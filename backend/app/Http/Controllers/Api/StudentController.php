<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStudentRequest;
use App\Http\Requests\UpdateStudentRequest;
use App\Http\Resources\StudentResource;
use App\Models\Person;
use App\Models\Student;
use App\Services\Admissions\SnilsService;
use App\Services\StudentCsvService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentController extends Controller
{
    public function __construct(private readonly StudentCsvService $studentCsvService, private readonly SnilsService $snils)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $students = Student::query()
            ->with('group.educationProgram.specialty')
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
        $student = DB::transaction(function () use ($request): Student {
            $data = $request->validated();
            $personId = null;
            if (filled($data['snils'] ?? null)) {
                $normalizedSnils = $this->snils->normalize($data['snils']);
                $hash = $this->snils->hash($normalizedSnils);
                $person = Person::query()->firstOrCreate(
                    ['snils_hash' => $hash],
                    [
                        'last_name' => $data['last_name'],
                        'first_name' => $data['first_name'],
                        'middle_name' => $data['middle_name'] ?? null,
                        'birth_date' => $data['birth_date'] ?? null,
                        'phone' => $data['phone'] ?? null,
                        'email' => $data['email'] ?? null,
                        'snils' => $normalizedSnils,
                        'snils_hash' => $hash,
                        'status' => 'active',
                    ],
                );
                $data['snils'] = $normalizedSnils;
                $personId = $person->id;
            }

            return Student::create([...$data, 'person_id' => $personId]);
        });

        return (new StudentResource($student->load('group.educationProgram.specialty')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Student $student): StudentResource
    {
        return new StudentResource($student->load('group.educationProgram.specialty'));
    }

    public function update(UpdateStudentRequest $request, Student $student): StudentResource
    {
        $student->update($request->validated());

        return new StudentResource($student->load('group.educationProgram.specialty'));
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
