<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FisIntegration\Exceptions\FisIntegrationException;
use App\Services\FisIntegration\FisDictionaryIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Загрузка справочников ФИС ГИА и Приёма в портал.
 *
 * Разбор и применение разделены намеренно: пакет в ФИС — операция по закону,
 * и оператор должен сначала увидеть, что именно приедет в справочники портала.
 */
class FisDictionaryIntakeController extends Controller
{
    public function __construct(private readonly FisDictionaryIntakeService $intake)
    {
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'catalog' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->answer(fn (): array => $this->intake->preview(
            (string) file_get_contents($data['file']->getRealPath()),
            $data['catalog'] ?? null,
        ));
    }

    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'catalog' => ['nullable', 'string', 'max:100'],
            'dictionary' => ['nullable', 'string', 'max:100'],
            'environment' => ['nullable', 'in:test,production'],
        ]);

        return $this->answer(fn (): array => $this->intake->apply(
            (string) file_get_contents($data['file']->getRealPath()),
            $data['environment'] ?? 'test',
            $data['catalog'] ?? null,
            $data['dictionary'] ?? null,
        ));
    }

    private function answer(callable $callback): JsonResponse
    {
        try {
            return response()->json(['data' => $callback()]);
        } catch (FisIntegrationException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }
    }
}
