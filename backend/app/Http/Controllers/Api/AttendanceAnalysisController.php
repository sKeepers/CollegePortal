<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AttendanceAnalysisService;
use Illuminate\Http\JsonResponse;

class AttendanceAnalysisController extends Controller
{
    public function teachersToday(AttendanceAnalysisService $service): JsonResponse
    {
        return response()->json($service->teachersToday());
    }

    public function studentsToday(AttendanceAnalysisService $service): JsonResponse
    {
        return response()->json($service->studentsToday());
    }
}
