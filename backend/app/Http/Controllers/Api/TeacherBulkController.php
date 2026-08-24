<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Bulk\TeacherBulkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Массовые действия над преподавателями.
 *
 * Пока действие одно — выдача учётных записей. Устроено как у студентов:
 * `preview` считает и ничего не пишет, `apply` пишет и **возвращает логины с
 * паролями списком один раз**. Второго раза не будет: в базе лежит хеш.
 */
class TeacherBulkController extends Controller
{
    public function __construct(private readonly TeacherBulkService $service)
    {
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->authorizeAction($request, $data['action']);

        return response()->json(['data' => $this->service->preview($data['action'], $data)]);
    }

    public function apply(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->authorizeAction($request, $data['action']);

        return response()->json([
            'message' => 'Массовая операция преподавателей выполнена.',
            'data' => $this->service->apply($data['action'], $data, $request),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'ids' => ['nullable', 'array'],
            'ids.*' => ['integer'],
            'filter' => ['nullable', 'array'],
            'action' => ['required', 'string'],
            'selection_scope' => ['nullable', 'string'],
        ]);
    }

    private function authorizeAction(Request $request, string $action): void
    {
        $permission = TeacherBulkService::PERMISSIONS[$action] ?? null;

        if (! $permission) {
            throw ValidationException::withMessages(['action' => ['Неизвестное массовое действие.']]);
        }

        if (! $request->user()?->hasPermission($permission)) {
            abort(403, 'Недостаточно прав для массовой операции.');
        }
    }
}
