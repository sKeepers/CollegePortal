<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Bulk\StudentBulkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class StudentBulkController extends Controller
{
    public function __construct(private readonly StudentBulkService $service)
    {
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->authorizeAction($request, $data['action']);

        return response()->json(['data' => $this->service->preview($data['action'], $data, $data['payload'] ?? [])]);
    }

    public function apply(Request $request): Response
    {
        $data = $this->validated($request);
        $this->authorizeAction($request, $data['action']);
        $result = $this->service->apply($data['action'], $data, $data['payload'] ?? [], $request);

        if ($result instanceof Response) {
            return $result;
        }

        return response()->json(['message' => 'Массовая операция студентов выполнена.', 'data' => $result]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'ids' => ['nullable', 'array'],
            'ids.*' => ['integer'],
            'filter' => ['nullable', 'array'],
            'action' => ['required', 'string'],
            'payload' => ['nullable', 'array'],
            'selection_scope' => ['nullable', 'string'],
        ]);
    }

    private function authorizeAction(Request $request, string $action): void
    {
        $permission = StudentBulkService::PERMISSIONS[$action] ?? null;
        if (! $permission) {
            throw ValidationException::withMessages(['action' => ['Неизвестное массовое действие.']]);
        }

        if (! $request->user()?->hasPermission($permission)) {
            abort(403, 'Недостаточно прав для массовой операции.');
        }
    }
}
