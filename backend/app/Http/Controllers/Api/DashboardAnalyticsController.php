<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardAnalyticsService;

class DashboardAnalyticsController extends Controller
{
    public function executive(DashboardAnalyticsService $analytics): array
    {
        return $analytics->executive();
    }
}
