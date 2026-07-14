<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FisCommunicationLogResource;
use App\Models\FisCommunicationLog;
use App\Services\FisIntegration\FisDiagnosticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FisDiagnosticsController extends Controller
{
    public function show(FisDiagnosticsService $diagnostics): JsonResponse
    {
        return response()->json(['data' => $diagnostics->snapshot()]);
    }

    public function run(FisDiagnosticsService $diagnostics): JsonResponse
    {
        return response()->json(['data' => $diagnostics->snapshot(probeGateway: true)]);
    }

    public function logs(Request $request): AnonymousResourceCollection
    {
        $logs = FisCommunicationLog::query()
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->when($request->string('method')->toString(), fn ($query, string $method) => $query->where('method', 'like', "%{$method}%"))
            ->latest('occurred_at')
            ->paginate(min(max($request->integer('per_page', 50), 1), 100));

        return FisCommunicationLogResource::collection($logs);
    }
}
