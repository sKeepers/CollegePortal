<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGroupRequest;
use App\Http\Requests\UpdateGroupRequest;
use App\Http\Resources\GroupResource;
use App\Models\Group;
use App\Services\GroupCsvService;
use App\Services\AutoCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GroupController extends Controller
{
    public function __construct(
        private readonly GroupCsvService $groupCsvService,
        private readonly AutoCodeService $autoCodeService,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        // Список групп питает выпадающие списки на чужих экранах — фильтры
        // студентов берут из него и курс, и специальность. На странице в
        // двадцать строк половина групп в них не попадала, и фильтр молча
        // предлагал неполный выбор.
        $perPage = min(max((int) ($request->integer('per_page') ?: 20), 1), 200);

        $groups = Group::query()
            ->with('curator')
            ->with('educationProgram.specialty')
            ->withCount('students')
            ->orderBy('name')
            ->paginate($perPage);

        return GroupResource::collection($groups);
    }

    public function store(StoreGroupRequest $request): JsonResponse
    {
        $data = $request->validated();
        $yearStart = (int) ($data['year_start'] ?? now()->year);
        $data['name'] = $data['name'] ?: $this->autoCodeService->groupName($data['specialty'] ?? null, $yearStart, Group::academicYear() - $yearStart + 1);
        $group = Group::create($data);

        return (new GroupResource($group->load(['curator', 'educationProgram.specialty'])->loadCount('students')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Group $group): GroupResource
    {
        return new GroupResource($group->load(['curator', 'educationProgram.specialty'])->loadCount('students'));
    }

    public function update(UpdateGroupRequest $request, Group $group): GroupResource
    {
        $data = $request->validated();
        if (array_key_exists('name', $data) && ! $data['name']) {
            $yearStart = (int) ($data['year_start'] ?? $group->year_start);
            $data['name'] = $this->autoCodeService->groupName($data['specialty'] ?? $group->specialty, $yearStart, Group::academicYear() - $yearStart + 1);
        }
        $group->update($data);

        return new GroupResource($group->load(['curator', 'educationProgram.specialty'])->loadCount('students'));
    }

    public function destroy(Group $group): Response
    {
        $group->delete();

        return response()->noContent();
    }

    public function export(): StreamedResponse
    {
        return $this->groupCsvService->export();
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $summary = $this->groupCsvService->import($request->file('file'));

        return response()->json(['data' => $summary]);
    }
}
