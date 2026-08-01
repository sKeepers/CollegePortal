<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScanAccessPassRequest;
use App\Http\Resources\AccessEventResource;
use App\Models\AccessEvent;
use App\Services\Access\AccessControlService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccessGateController extends Controller
{
    public function scan(ScanAccessPassRequest $request, AccessControlService $service): AccessEventResource
    {
        return new AccessEventResource($service->scan($request->user(), $request->validated(), $request));
    }

    public function events(Request $request): AnonymousResourceCollection
    {
        $events = AccessEvent::query()
            ->with(['person.primaryStudent.group', 'person.primaryTeacher.employee.primaryDepartment', 'accessPoint', 'device', 'digitalIdentity'])
            ->when($request->string('result')->toString(), fn ($query, string $result) => $query->where('result', $result))
            ->when($request->string('direction')->toString(), fn ($query, string $direction) => $query->where('direction', $direction))
            ->when($request->integer('access_point_id'), fn ($query, int $id) => $query->where('access_point_id', $id))
            ->orderByDesc('event_time')
            ->paginate((int) $request->query('per_page', 50));

        return AccessEventResource::collection($events);
    }

    public function show(AccessEvent $accessEvent): AccessEventResource
    {
        return new AccessEventResource($accessEvent->load(['person.primaryStudent.group', 'person.primaryTeacher.employee.primaryDepartment', 'accessPoint', 'device', 'digitalIdentity']));
    }

    public function override(Request $request, AccessEvent $accessEvent, AccessControlService $service): AccessEventResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return new AccessEventResource($service->override($request->user(), $accessEvent, $data['reason'], $request));
    }
}
