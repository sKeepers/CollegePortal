<?php

namespace App\Services\FisIntegration\Xml;

use App\Models\EducationProgram;
use App\Models\FisExternalMapping;
use App\Services\Admissions\FisDictionaryMappingService;

/**
 * Перевод внутренних значений портала в идентификаторы справочников ФИС.
 *
 * Единственное правило: идентификатор либо известен из настроенного
 * сопоставления, либо не выдаётся вовсе. Ничего не угадывается — неверный ИД
 * справочника в официальном пакете это не опечатка, а недостоверные сведения.
 */
class FisReferenceResolver
{
    public function __construct(private readonly FisDictionaryMappingService $mappings)
    {
    }

    /** ИД элемента справочника ФИС по внутреннему элементу справочника портала. */
    public function fromReferenceItem(?int $referenceItemId, string $dictionary, string $environment): ?int
    {
        $value = $this->mappings->valueForReference($referenceItemId, $dictionary, $environment);

        return is_numeric($value) ? (int) $value : null;
    }

    /** Справочник №5 «Пол». В портале пол — строка, а не элемент справочника. */
    public function genderId(?string $gender): ?int
    {
        if (! filled($gender)) {
            return null;
        }

        $value = config('fis_api.dictionaries.gender.'.$gender);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * UID конкурсной группы. Конкурсы создаются в самой ФИС и портал их не
     * ведёт, поэтому связь «образовательная программа → конкурс» хранится
     * сопоставлением и заполняется оператором.
     */
    public function competitiveGroupUid(?int $educationProgramId, string $environment): ?string
    {
        if ($educationProgramId === null) {
            return null;
        }

        $value = FisExternalMapping::query()
            ->where('entity_type', EducationProgram::class)
            ->where('entity_id', $educationProgramId)
            ->where('external_type', 'fis:CompetitiveGroupUID')
            ->where('environment', $environment)
            ->value('external_id');

        return filled($value) ? (string) $value : null;
    }
}
