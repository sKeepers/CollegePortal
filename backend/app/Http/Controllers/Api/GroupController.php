<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGroupRequest;
use App\Http\Requests\UpdateGroupRequest;
use App\Http\Resources\GroupResource;
use App\Models\Group;
use App\Services\GroupCsvService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GroupController extends Controller
{
    public function __construct(private readonly GroupCsvService $groupCsvService)
    {
    }

    public function index(): AnonymousResourceCollection
    {
        $groups = Group::query()
            ->with('curator')
            ->with('educationProgram.specialty')
            ->withCount('students')
            ->orderBy('name')
            ->paginate(20);

        return GroupResource::collection($groups);
    }

    public function store(StoreGroupRequest $request): JsonResponse
    {
        $group = Group::create($request->validated());

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
        $group->update($request->validated());

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
