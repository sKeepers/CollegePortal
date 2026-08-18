<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FisIntegration\Exceptions\FisIntegrationException;
use App\Services\FisIntegration\FisCompetitiveGroupIntakeService;
use App\Services\FisIntegration\FisDictionaryIntakeService;
use App\Services\FisIntegration\GatewayFisTransport;
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

    public function preview(Request $request, GatewayFisTransport $gateway): JsonResponse
    {
        $data = $request->validate($this->rules());

        return $this->answer(function () use ($data, $gateway): array {
            $xml = $this->source($data, $gateway);

            return $this->parser->detectKind($xml) === 'institution_export'
                ? $this->competitiveGroups->preview($xml)
                : $this->dictionaries->preview($xml, $data['catalog'] ?? null);
        });
    }

    public function apply(Request $request, GatewayFisTransport $gateway): JsonResponse
    {
        $data = $request->validate($this->rules() + [
            'dictionary' => ['nullable', 'string', 'max:100'],
            'environment' => ['nullable', 'in:test,production'],
            // Переименовывать ли специальности под формулировки ФИС. По умолчанию
            // нет: в реестре ФИС (по крайней мере в тестовом контуре) название бывает устаревшим, а обмену имена не
            // мешают — специальность находится по коду.
            'rename' => ['sometimes', 'boolean'],
        ]);

        return $this->answer(function () use ($data, $gateway): array {
            $xml = $this->source($data, $gateway);
            $environment = $data['environment'] ?? 'test';

            return $this->parser->detectKind($xml) === 'institution_export'
                ? $this->competitiveGroups->apply($xml, $environment)
                : $this->dictionaries->apply($xml, $environment, $data['catalog'] ?? null, $data['dictionary'] ?? null, (bool) ($data['rename'] ?? false));
        });
    }

    /**
     * Файл **или** запрос к ФИС. Раньше был только файл, и после того как шлюз
     * научился приносить данные, оператору пришлось бы нажать диагностику,
     * скопировать XML, сохранить его в файл и загрузить обратно в тот же портал.
     *
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        return [
            'file' => ['required_without:fetch', 'file', 'max:20480'],
            'fetch' => ['required_without:file', 'nullable', 'in:dictionaries,dictionary,institution'],
            // Код нужен только составу справочника: у списка и у сведений об
            // организации выбирать нечего.
            'code' => ['required_if:fetch,dictionary', 'nullable', 'integer', 'min:1'],
            'catalog' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * XML, который будем разбирать: из загруженного файла или прямо из ФИС.
     *
     * @param  array<string, mixed>  $data
     */
    private function source(array $data, GatewayFisTransport $gateway): string
    {
        if (($data['fetch'] ?? null) === null) {
            return (string) file_get_contents($data['file']->getRealPath());
        }

        $answer = match ($data['fetch']) {
            'dictionaries' => $gateway->dictionariesList(),
            'dictionary' => $gateway->dictionaryDetails((string) $data['code']),
            'institution' => $gateway->institutionInfo(),
        };

        // Отказ ФИС приходит с успешным кодом HTTP и телом `Error`: шлюз уже
        // разобрал это и сказал `ok: false`. Пересказываем причину как есть.
        if (! ($answer['ok'] ?? false)) {
            throw new FisIntegrationException(trim(($answer['error_code'] ?? 'fis_error').': '.($answer['message'] ?? '')));
        }

        $xml = (string) ($answer['data'] ?? '');

        if ($xml === '') {
            throw new FisIntegrationException('ФИС ответила без данных: разбирать нечего.');
        }

        return $xml;
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
