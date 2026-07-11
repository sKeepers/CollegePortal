<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'CollegePortal',
            'timestamp' => now()->toISOString(),
        ]);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'app' => 'ok',
            'database' => $this->databaseStatus(),
            'storage' => $this->storageStatus(),
            'cache' => $this->cacheStatus(),
        ];
        $ok = collect($checks)->every(fn ($status) => $status === 'ok');

        return response()->json([
            'status' => $ok ? 'ok' : 'error',
            'checks' => $checks,
            'timestamp' => now()->toISOString(),
        ], $ok ? 200 : 503);
    }

    public function health(): JsonResponse
    {
        return $this->ready();
    }

    private function databaseStatus(): string
    {
        try {
            DB::select('select 1');
            return 'ok';
        } catch (\Throwable) {
            return 'error';
        }
    }

    private function storageStatus(): string
    {
        try {
            $path = 'health/.healthcheck';
            Storage::disk('local')->put($path, now()->toISOString());
            Storage::disk('local')->delete($path);
            return 'ok';
        } catch (\Throwable) {
            return 'error';
        }
    }

    private function cacheStatus(): string
    {
        try {
            Cache::put('health_check', 'ok', 10);
            return Cache::get('health_check') === 'ok' ? 'ok' : 'error';
        } catch (\Throwable) {
            return 'warning';
        }
    }
}
