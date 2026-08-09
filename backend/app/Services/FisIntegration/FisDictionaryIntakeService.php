<?php

namespace App\Services\FisIntegration;

use App\Models\FisExternalMapping;
use App\Models\ReferenceCatalog;
use App\Models\ReferenceItem;
use App\Models\Specialty;
use App\Services\AuditLogService;
use App\Services\FisIntegration\Exceptions\FisIntegrationException;
use App\Services\FisIntegration\Xml\FisDictionaryXmlParser;
use Illuminate\Support\Facades\DB;

/**
 * Приём справочников ФИС ГИА и Приёма в портал.
 *
 * Две вещи, ради которых это сделано:
 *
 * 1. Специальности не нужно набивать руками — справочник направлений
 *    подготовки ФИС отдаёт код, название и укрупнённую группу.
 * 2. Идентификаторы справочников ФИС перестают быть неизвестными: сборка
 *    исходящего пакета отказывалась работать именно из-за них.
 *
 * Источник — XML-ответ ФИС. Сейчас это выгруженный файл, позже тот же разбор
 * получит ответ шлюза: разбор от источника не зависит.
 */
class FisDictionaryIntakeService
{
    public function __construct(private readonly FisDictionaryXmlParser $parser)
    {
    }

    /**
     * Разбор без единой записи в базу: оператор сначала смотрит, что пришло.
     *
     * @return array<string, mixed>
     */
    public function preview(string $xml, ?string $catalogCode = null): array
    {
        if ($this->parser->detectKind($xml) === 'dictionary_list') {
            return [
                'kind' => 'dictionary_list',
                'dictionaries' => $this->parser->parseDictionaryList($xml),
            ];
        }

        $data = $this->parser->parseDictionaryData($xml);

        if ($data['kind'] === 'directions') {
            return ['kind' => 'directions', 'dictionary' => $this->dictionaryHead($data)] + $this->planDirections($data['items']);
        }

        $catalogCode ??= $this->suggestedCatalog($data['code']);

        return ['kind' => 'plain', 'dictionary' => $this->dictionaryHead($data), 'catalog' => $catalogCode]
            + $this->planPlain($data['items'], $catalogCode);
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(string $xml, string $environment, ?string $catalogCode = null, ?string $dictionary = null): array
    {
        if ($this->parser->detectKind($xml) === 'dictionary_list') {
            throw new FisIntegrationException('Список справочников применять некуда: это оглавление, а не состав справочника. Загрузите ответ метода получения элементов справочника.');
        }

        $data = $this->parser->parseDictionaryData($xml);

        return $data['kind'] === 'directions'
            ? $this->applyDirections($data, $environment)
            : $this->applyPlain($data, $environment, $catalogCode, $dictionary);
    }

    /** @return array{code:?string,name:?string,item_count:int} */
    private function dictionaryHead(array $data): array
    {
        return ['code' => $data['code'], 'name' => $data['name'], 'item_count' => count($data['items'])];
    }

    /**
     * Направления подготовки → специальности портала. Сопоставление по коду:
     * это он печатается в дипломе и по нему специальность узнают люди.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function planDirections(array $items): array
    {
        $existing = Specialty::query()->pluck('name', 'code');
        $create = [];
        $update = [];
        $skipped = [];

        foreach ($items as $item) {
            $code = $this->normalizeCode($item['code'] ?? null);
            $name = trim((string) ($item['name'] ?? ''));

            if ($code === null || $name === '') {
                $skipped[] = ['id' => $item['id'] ?? null, 'name' => $name ?: null, 'reason' => 'В записи нет кода или названия направления.'];

                continue;
            }

            if (! $existing->has($code)) {
                $create[] = ['code' => $code, 'name' => $name, 'ugs' => $item['ugs_name'] ?? null];
            } elseif (trim((string) $existing->get($code)) !== $name) {
                $update[] = ['code' => $code, 'name_current' => $existing->get($code), 'name_incoming' => $name];
            }
        }

        return ['will_create' => $create, 'will_update' => $update, 'skipped' => $skipped];
    }

    /**
     * @return array<string, mixed>
     */
    private function applyDirections(array $data, string $environment): array
    {
        $created = 0;
        $updated = 0;
        $mapped = 0;
        $skipped = [];

        DB::transaction(function () use ($data, $environment, &$created, &$updated, &$mapped, &$skipped): void {
            foreach ($data['items'] as $item) {
                $code = $this->normalizeCode($item['code'] ?? null);
                $name = trim((string) ($item['name'] ?? ''));

                if ($code === null || $name === '') {
                    $skipped[] = ['id' => $item['id'] ?? null, 'name' => $name ?: null, 'reason' => 'В записи нет кода или названия направления.'];

                    continue;
                }

                $specialty = Specialty::query()->where('code', $code)->first();

                if (! $specialty) {
                    $specialty = Specialty::query()->create([
                        'code' => $code,
                        'name' => $name,
                        'education_level' => 'Среднее профессиональное образование',
                    ]);
                    $created++;
                } elseif (trim((string) $specialty->name) !== $name) {
                    $specialty->update(['name' => $name]);
                    $updated++;
                }

                if (filled($item['id'] ?? null)) {
                    $this->putMapping(Specialty::class, $specialty->getKey(), 'DirectionID', (string) $item['id'], $environment, [
                        'ugs_code' => $item['ugs_code'] ?? null,
                        'ugs_name' => $item['ugs_name'] ?? null,
                        'parent_direction_id' => $item['parent_id'] ?? null,
                        'qualification_code' => $item['qualification_code'] ?? null,
                    ]);
                    $mapped++;
                }
            }
        });

        AuditLogService::log('fis_dictionaries', 'directions_applied', null, null, [
            'dictionary' => $data['code'], 'created' => $created, 'updated' => $updated, 'mapped' => $mapped,
        ]);

        return [
            'kind' => 'directions',
            'dictionary' => $this->dictionaryHead($data),
            'created' => $created,
            'updated' => $updated,
            'mapped' => $mapped,
            'skipped' => $skipped,
        ];
    }

    /**
     * Обычный справочник → сопоставление с элементами справочника портала.
     * Совпадение только по точному названию после приведения регистра, «ё» и
     * пробелов. Похожие названия не склеиваем: неверный ИД справочника в
     * официальном пакете — это недостоверные сведения, а не мелкая неточность.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function planPlain(array $items, ?string $catalogCode): array
    {
        if ($catalogCode === null) {
            return [
                'will_map' => [],
                'unmatched' => [],
                'message' => 'Не указано, в какой справочник портала класть эти записи. Выберите справочник и повторите.',
            ];
        }

        $portal = $this->portalItems($catalogCode);
        $map = [];
        $unmatched = [];

        foreach ($items as $item) {
            $key = $this->normalizeName($item['name'] ?? null);
            $match = $key === null ? null : ($portal[$key] ?? null);

            $match
                ? $map[] = ['fis_id' => $item['id'], 'fis_name' => $item['name'], 'portal_code' => $match->code, 'portal_name' => $match->name]
                : $unmatched[] = ['fis_id' => $item['id'], 'fis_name' => $item['name']];
        }

        return ['will_map' => $map, 'unmatched' => $unmatched];
    }

    /**
     * @return array<string, mixed>
     */
    private function applyPlain(array $data, string $environment, ?string $catalogCode, ?string $dictionary): array
    {
        $catalogCode ??= $this->suggestedCatalog($data['code']);
        $dictionary ??= $this->suggestedDictionary($data['code']);

        if ($catalogCode === null || $dictionary === null) {
            throw new FisIntegrationException('Непонятно, куда класть справочник «'.($data['name'] ?: $data['code']).'»: укажите справочник портала и имя справочника ФИС явно.');
        }

        $portal = $this->portalItems($catalogCode);
        $mapped = 0;
        $unmatched = [];

        DB::transaction(function () use ($data, $portal, $dictionary, $environment, &$mapped, &$unmatched): void {
            foreach ($data['items'] as $item) {
                $key = $this->normalizeName($item['name'] ?? null);
                $match = $key === null ? null : ($portal[$key] ?? null);

                if (! $match) {
                    $unmatched[] = ['fis_id' => $item['id'], 'fis_name' => $item['name']];

                    continue;
                }

                $this->putMapping(ReferenceItem::class, $match->id, $dictionary, (string) $item['id'], $environment);
                $mapped++;
            }
        });

        AuditLogService::log('fis_dictionaries', 'dictionary_applied', null, null, [
            'dictionary' => $data['code'], 'catalog' => $catalogCode, 'mapped' => $mapped, 'unmatched' => count($unmatched),
        ]);

        return [
            'kind' => 'plain',
            'dictionary' => $this->dictionaryHead($data),
            'catalog' => $catalogCode,
            'mapped' => $mapped,
            'unmatched' => $unmatched,
        ];
    }

    /** @return array<string, ReferenceItem> */
    private function portalItems(string $catalogCode): array
    {
        $catalog = ReferenceCatalog::query()->where('code', $catalogCode)->first();

        if (! $catalog) {
            throw new FisIntegrationException('Справочник портала «'.$catalogCode.'» не найден.');
        }

        $items = [];
        foreach (ReferenceItem::query()->where('catalog_id', $catalog->id)->get() as $item) {
            $key = $this->normalizeName($item->name);
            if ($key !== null) {
                $items[$key] = $item;
            }
        }

        return $items;
    }

    private function putMapping(string $entityType, int $entityId, string $dictionary, string $externalId, string $environment, array $metadata = []): void
    {
        FisExternalMapping::query()->updateOrCreate(
            ['entity_type' => $entityType, 'entity_id' => $entityId, 'external_type' => 'fis:'.$dictionary, 'environment' => $environment],
            ['external_id' => $externalId, 'metadata' => array_filter($metadata, fn ($value): bool => $value !== null)],
        );
    }

    private function suggestedCatalog(?string $dictionaryCode): ?string
    {
        return $dictionaryCode === null ? null : (config('fis_api.dictionary_intake.'.$dictionaryCode.'.catalog') ?: null);
    }

    private function suggestedDictionary(?string $dictionaryCode): ?string
    {
        return $dictionaryCode === null ? null : (config('fis_api.dictionary_intake.'.$dictionaryCode.'.dictionary') ?: null);
    }

    private function normalizeCode(?string $code): ?string
    {
        $code = trim((string) $code);

        return $code === '' ? null : $code;
    }

    private function normalizeName(?string $name): ?string
    {
        $name = preg_replace('/\s+/u', ' ', trim((string) $name));
        $name = str_replace(['ё', 'Ё'], ['е', 'Е'], (string) $name);

        return $name === '' ? null : mb_strtolower($name);
    }
}
