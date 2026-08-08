<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAccessPointRequest;
use App\Http\Resources\AccessPointResource;
use App\Models\AccessPoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccessPointController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $points = AccessPoint::query()
            ->with('building')
            ->when($request->integer('building_id'), fn ($query, int $id) => $query->where('building_id', $id))
            ->when($request->boolean('only_active'), fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return AccessPointResource::collection($points);
    }

    public function store(StoreAccessPointRequest $request): AccessPointResource
    {
        $point = AccessPoint::create($request->validated());

        return new AccessPointResource($point->load('building'));
    }

    public function update(StoreAccessPointRequest $request, AccessPoint $accessPoint): AccessPointResource
    {
        $accessPoint->update($request->validated());

        return new AccessPointResource($accessPoint->load('building'));
    }

    /**
     * События проходной переживают удаление точки: связь обнуляется, а название
     * остается строкой в самом событии. История прохода не должна зависеть от
     * того, что точку переименовали или сняли.
     */
    public function destroy(AccessPoint $accessPoint): JsonResponse
    {
        $accessPoint->delete();

        return response()->json(['message' => 'Точка прохода удалена.']);
    }
}
