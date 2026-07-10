<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AttendanceAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceAnalysisController extends Controller
{
    public function teachersToday(Request $request, AttendanceAnalysisService $service): JsonResponse
    {
        return response()->json($service->teachersToday(filters: $this->filters($request)));
    }

    public function studentsToday(Request $request, AttendanceAnalysisService $service): JsonResponse
    {
        return response()->json($service->studentsToday(filters: $this->filters($request)));
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'status' => ['nullable', 'string', 'max:64'],
            'group_id' => ['nullable', 'integer'],
            'teacher_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);
    }
}
