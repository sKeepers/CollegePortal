<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FisIntegration\Exceptions\FisIntegrationException;
use App\Services\FisIntegration\FisCompetitiveGroupIntakeService;
use App\Services\FisIntegration\FisDictionaryIntakeService;
use App\Services\FisIntegration\Xml\FisDictionaryXmlParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Приём данных ФИС ГИА и Приёма в портал.
 *
 * Один вход на все ответы: справочники, состав справочника и сведения об
 * организации с конкурсами. Что именно загрузили, определяется по самому
 * файлу — оператору незачем помнить, какой метод сервиса его отдал.
 *
 * Разбор и применение разделены намеренно: пакет в ФИС — операция по закону,
 * и человек должен сначала увидеть, что приедет в портал.
 */
class FisDictionaryIntakeController extends Controller
{
    public function __construct(
        private readonly FisDictionaryIntakeService $dictionaries,
        private readonly FisCompetitiveGroupIntakeService $competitiveGroups,
        private readonly FisDictionaryXmlParser $parser,
    ) {
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'catalog' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->answer(function () use ($data): array {
            $xml = (string) file_get_contents($data['file']->getRealPath());

            return $this->parser->detectKind($xml) === 'institution_export'
                ? $this->competitiveGroups->preview($xml)
                : $this->dictionaries->preview($xml, $data['catalog'] ?? null);
        });
    }

    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'catalog' => ['nullable', 'string', 'max:100'],
            'dictionary' => ['nullable', 'string', 'max:100'],
            'environment' => ['nullable', 'in:test,production'],
        ]);

        return $this->answer(function () use ($data): array {
            $xml = (string) file_get_contents($data['file']->getRealPath());
            $environment = $data['environment'] ?? 'test';

            return $this->parser->detectKind($xml) === 'institution_export'
                ? $this->competitiveGroups->apply($xml, $environment)
                : $this->dictionaries->apply($xml, $environment, $data['catalog'] ?? null, $data['dictionary'] ?? null);
        });
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
