<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSubjectRequest;
use App\Http\Requests\UpdateSubjectRequest;
use App\Http\Resources\SubjectResource;
use App\Models\Subject;
use App\Services\SubjectCsvService;
use App\Services\AutoCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Support\Http\PageSize;

class SubjectController extends Controller
{
    public function __construct(
        private readonly SubjectCsvService $subjectCsvService,
        private readonly AutoCodeService $autoCodeService,
    ) {
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
            ->paginate(PageSize::from($request, 20));

        return SubjectResource::collection($subjects);
    }

    public function store(StoreSubjectRequest $request): JsonResponse
    {
        $data = $request->validated();
        $teacherIds = $data['teacher_ids'] ?? [];
        unset($data['teacher_ids']);
        // Код необязателен, поэтому ключа может не быть вовсе: клиент, который
        // просто не прислал поле, не должен получать пятисотую.
        $data['code'] = ($data['code'] ?? null) ?: $this->autoCodeService->subjectCode($data['name'] ?? null);

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
        if (array_key_exists('code', $data) && ! $data['code']) {
            $data['code'] = $this->autoCodeService->subjectCode($data['name'] ?? $subject->name);
        }

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
