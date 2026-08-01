<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessDevice;
use App\Models\AccessPoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccessDeviceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => AccessDevice::query()->with('accessPoint')->orderBy('name')->get()]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:120'],
            'name' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in(['mobile_camera', 'hid_scanner', 'manual'])],
            'access_point_id' => ['nullable', 'integer', 'exists:access_points,id'],
            'access_point' => ['nullable', 'string', 'max:255'],
        ]);

        $pointId = $data['access_point_id'] ?? null;
        if (! $pointId && ! empty($data['access_point'])) {
            $pointId = AccessPoint::query()->firstOrCreate(['name' => $data['access_point']], ['location' => $data['access_point'], 'direction_mode' => 'both', 'active' => true])->id;
        }

        $device = AccessDevice::query()->updateOrCreate(
            ['identifier' => $data['identifier']],
            ['name' => $data['name'] ?? $data['identifier'], 'type' => $data['type'], 'access_point_id' => $pointId, 'active' => true, 'last_seen_at' => now()]
        );

        return response()->json(['data' => $device->fresh('accessPoint')]);
    }
}
