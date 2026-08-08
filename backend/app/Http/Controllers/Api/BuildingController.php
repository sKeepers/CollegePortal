<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBuildingRequest;
use App\Http\Resources\BuildingResource;
use App\Models\Building;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BuildingController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $buildings = Building::query()
            ->withCount('accessPoints')
            ->when($request->boolean('only_active'), fn ($query) => $query->where('is_active', true))
            ->when($request->boolean('with_points'), fn ($query) => $query->with([
                'accessPoints' => fn ($points) => $points->orderBy('sort_order')->orderBy('name'),
            ]))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return BuildingResource::collection($buildings);
    }

    public function store(StoreBuildingRequest $request): BuildingResource
    {
        $building = Building::create($request->validated());

        return new BuildingResource($building->loadCount('accessPoints'));
    }

    public function update(StoreBuildingRequest $request, Building $building): BuildingResource
    {
        $building->update($request->validated());

        return new BuildingResource($building->loadCount('accessPoints'));
    }

    /**
     * Корпус с точками прохода не удаляется: события проходной ссылаются на них,
     * и каскад унес бы историю. Сначала точки, потом корпус — так оператор видит,
     * что именно он собирается стереть.
     */
    public function destroy(Building $building): JsonResponse
    {
        if ($building->accessPoints()->exists()) {
            return response()->json([
                'message' => 'Сначала удалите точки прохода этого корпуса.',
            ], 422);
        }

        $building->delete();

        return response()->json(['message' => 'Корпус удален.']);
    }
}
