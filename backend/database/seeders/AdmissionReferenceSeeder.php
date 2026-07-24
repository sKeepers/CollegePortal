<?php

namespace Database\Seeders;

use App\Models\ReferenceCatalog;
use App\Services\ReferenceService;
use App\Support\Admissions\AdmissionReferenceCatalogs;
use Illuminate\Database\Seeder;

/**
 * Seeder системных справочников приемной комиссии.
 */
class AdmissionReferenceSeeder extends Seeder
{
    /**
     * Заполняет системные справочники приемной комиссии без создания бизнес-сущностей.
     */
    public function run(): void
    {
        foreach (AdmissionReferenceCatalogs::catalogs() as $catalogData) {
            $items = $catalogData['items'];
            unset($catalogData['items']);

            $catalog = ReferenceCatalog::query()->updateOrCreate(
                ['code' => $catalogData['code']],
                [
                    'name' => $catalogData['name'],
                    'description' => $catalogData['description'],
                    'is_system' => true,
                ],
            );

            foreach ($items as $index => $item) {
                $catalog->items()->updateOrCreate(
                    ['code' => $item['code']],
                    [
                        'name' => $item['name'],
                        'sort_order' => ($index + 1) * 10,
                        'is_active' => true,
                        'metadata' => [
                            'is_system' => true,
                            'module' => 'admissions',
                            ...($item['metadata'] ?? []),
                        ],
                    ],
                );
            }

            ReferenceService::forget($catalog->code);
        }
    }
}
