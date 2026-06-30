<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTeacherRequest;
use App\Http\Requests\UpdateTeacherRequest;
use App\Http\Resources\TeacherResource;
use App\Models\Teacher;
use App\Services\TeacherCsvService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TeacherController extends Controller
{
    public function __construct(private readonly TeacherCsvService $teacherCsvService)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $teachers = Teacher::query()
            ->when($request->string('department')->toString(), fn ($query, string $department) => $query->where('department', $department))
            ->when($request->boolean('active_only'), fn ($query) => $query->where('is_active', true))
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $search = mb_strtolower($search);

                $query->where(function ($query) use ($search): void {
                    $query
                        ->whereRaw('lower(last_name) like ?', ["%{$search}%"])
                        ->orWhereRaw('lower(first_name) like ?', ["%{$search}%"])
                        ->orWhereRaw('lower(middle_name) like ?', ["%{$search}%"]);
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(20);

        return TeacherResource::collection($teachers);
    }

    public function store(StoreTeacherRequest $request): JsonResponse
    {
        $teacher = Teacher::create($request->validated());

        return (new TeacherResource($teacher))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Teacher $teacher): TeacherResource
    {
        return new TeacherResource($teacher);
    }

    public function update(UpdateTeacherRequest $request, Teacher $teacher): TeacherResource
    {
        $teacher->update($request->validated());

        return new TeacherResource($teacher);
    }

    public function destroy(Teacher $teacher): Response
    {
        $teacher->delete();

        return response()->noContent();
    }

    public function export(): StreamedResponse
    {
        return $this->teacherCsvService->export();
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $summary = $this->teacherCsvService->import($request->file('file'));

        return response()->json(['data' => $summary]);
    }
}
