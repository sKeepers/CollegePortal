<?php

namespace App\Http\Controllers\Api\Admissions;

use App\Http\Controllers\Controller;
use App\Services\Admissions\AdmissionDocumentReadinessService;
use Illuminate\Http\JsonResponse;

class DocumentReadinessController extends Controller
{
    public function __construct(private readonly AdmissionDocumentReadinessService $readiness)
    {
    }

    public function show(int $application): JsonResponse
    {
        return response()->json(['data' => $this->readiness->forApplication($application)]);
    }
}
