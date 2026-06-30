<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSubjectRequest;
use App\Http\Requests\UpdateSubjectRequest;
use App\Http\Resources\SubjectResource;
use App\Models\Subject;
use App\Services\SubjectCsvService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubjectController extends Controller
{
    public function __construct(private readonly SubjectCsvService $subjectCsvService)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $subjects = Subject::query()
            ->with('teachers')
            ->when($request->string('department')->toString(), fn ($query, string $department) => $query->where('department', $department))
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $search = mb_strtolower($search);

                $query->where(function ($query) use ($search): void {
                    $query
                        ->whereRaw('lower(name) like ?', ["%{$search}%"])
                        ->orWhereRaw('lower(code) like ?', ["%{$search}%"]);
                });
            })
            ->orderBy('name')
            ->paginate(20);

        return SubjectResource::collection($subjects);
    }

    public function store(StoreSubjectRequest $request): JsonResponse
    {
        $data = $request->validated();
        $teacherIds = $data['teacher_ids'] ?? [];
        unset($data['teacher_ids']);

        $subject = Subject::create($data);
        $subject->teachers()->sync($teacherIds);

        return (new SubjectResource($subject->load('teachers')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Subject $subject): SubjectResource
    {
        return new SubjectResource($subject->load('teachers'));
    }

    public function update(UpdateSubjectRequest $request, Subject $subject): SubjectResource
    {
        $data = $request->validated();
        $teacherIds = $data['teacher_ids'] ?? null;
        unset($data['teacher_ids']);

        $subject->update($data);

        if ($teacherIds !== null) {
            $subject->teachers()->sync($teacherIds);
        }

        return new SubjectResource($subject->load('teachers'));
    }

    public function destroy(Subject $subject): Response
    {
        $subject->delete();

        return response()->noContent();
    }

    public function export(): StreamedResponse
    {
        return $this->subjectCsvService->export();
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $summary = $this->subjectCsvService->import($request->file('file'));

        return response()->json(['data' => $summary]);
    }
}
