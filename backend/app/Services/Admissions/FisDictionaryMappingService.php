<?php

namespace App\Services\Admissions;

use App\Models\FisExternalMapping;
use App\Models\ReferenceItem;

class FisDictionaryMappingService
{
    public const ENVIRONMENT_TEST = 'test';

    public function valueForReference(?int $referenceItemId, string $dictionary, string $environment = self::ENVIRONMENT_TEST): ?string
    {
        if ($referenceItemId === null) {
            return null;
        }

        return FisExternalMapping::query()
            ->where('entity_type', ReferenceItem::class)
            ->where('entity_id', $referenceItemId)
            ->where('external_type', $this->externalType($dictionary))
            ->where('environment', $environment)
            ->value('external_id');
    }

    public function missingReference(?int $referenceItemId, string $dictionary, string $field): ?array
    {
        if ($referenceItemId === null) {
            return [
                'code' => $field.'_missing',
                'field' => $field,
                'dictionary' => $dictionary,
                'message' => 'Не выбран внутренний справочник для сопоставления с ФИС.',
            ];
        }

        if ($this->valueForReference($referenceItemId, $dictionary) === null) {
            return [
                'code' => $field.'_fis_mapping_missing',
                'field' => $field,
                'dictionary' => $dictionary,
                'message' => 'Не настроено сопоставление внутреннего справочника с ФИС.',
            ];
        }

        return null;
    }

    private function externalType(string $dictionary): string
    {
        return 'fis:'.$dictionary;
    }
}
