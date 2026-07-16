<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessPoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccessPointController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $points = AccessPoint::query()
            ->when($request->boolean('active'), fn ($query) => $query->where('active', true))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $points]);
    }
}
