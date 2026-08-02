<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RestoreDatabaseSnapshotRequest;
use App\Services\PostgresBackupService;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class DatabaseBackupController extends Controller
{
    public function __construct(private readonly PostgresBackupService $backups)
    {
    }

    public function index(): JsonResponse
    {
        try {
            return response()->json(['data' => $this->backups->snapshots()]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function store(): JsonResponse
    {
        try {
            return response()->json(['message' => 'Полный архив PostgreSQL создан.', 'data' => $this->backups->create(request()->user())], Response::HTTP_CREATED);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function restore(RestoreDatabaseSnapshotRequest $request, string $snapshot): JsonResponse
    {
        try {
            return response()->json(['message' => 'База данных восстановлена. Перед восстановлением создан аварийный архив.', 'data' => $this->backups->restore($snapshot, $request->user())]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
