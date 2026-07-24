<?php

namespace Tests\Unit;

use App\Support\Admissions\AdmissionReferenceCatalogs;
use PHPUnit\Framework\TestCase;

/**
 * Unit-тесты канонического источника admissions-справочников.
 */
class AdmissionReferenceCatalogsTest extends TestCase
{
    /**
     * Проверяет уникальность кодов и покрытие минимального состава BACK-001.
     */
    public function test_catalog_codes_are_unique_and_cover_back_001_scope(): void
    {
        $codes = AdmissionReferenceCatalogs::codes();

        $this->assertSame($codes, array_values(array_unique($codes)));
        $this->assertContains('admission_application_statuses', $codes);
        $this->assertContains('applicant_document_types', $codes);
        $this->assertContains('achievement_types', $codes);
        $this->assertContains('admission_exam_types', $codes);
        $this->assertContains('competition_types', $codes);
        $this->assertContains('admission_rejection_reasons', $codes);
    }

    /**
     * Проверяет, что каждый каталог содержит элементы с валидными кодами и названиями.
     */
    public function test_each_catalog_has_items_with_codes_and_names(): void
    {
        foreach (AdmissionReferenceCatalogs::catalogs() as $catalog) {
            $this->assertNotEmpty($catalog['code']);
            $this->assertNotEmpty($catalog['name']);
            $this->assertNotEmpty($catalog['items']);

            foreach ($catalog['items'] as $item) {
                $this->assertMatchesRegularExpression('/^[a-z0-9_\-]+$/', $item['code']);
                $this->assertNotEmpty($item['name']);
            }
        }
    }
}
