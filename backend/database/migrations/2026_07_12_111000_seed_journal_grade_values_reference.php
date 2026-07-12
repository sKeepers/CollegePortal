<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reference_catalogs') || ! Schema::hasTable('reference_items')) {
            return;
        }

        $now = now();
        DB::table('reference_catalogs')->updateOrInsert(
            ['code' => 'journal_grade_values'],
            [
                'name' => 'Значения оценок журнала',
                'description' => 'Системный справочник допустимых значений оценок электронного журнала',
                'is_system' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        $catalogId = DB::table('reference_catalogs')->where('code', 'journal_grade_values')->value('id');
        foreach ([
            ['code' => '5', 'name' => '5'],
            ['code' => '4', 'name' => '4'],
            ['code' => '3', 'name' => '3'],
            ['code' => '2', 'name' => '2'],
            ['code' => 'pass', 'name' => 'Зачет'],
            ['code' => 'fail', 'name' => 'Незачет'],
            ['code' => 'exempt', 'name' => 'Освобожден'],
            ['code' => 'not_certified', 'name' => 'Не аттестован'],
        ] as $index => $item) {
            DB::table('reference_items')->updateOrInsert(
                ['catalog_id' => $catalogId, 'code' => $item['code']],
                [
                    'name' => $item['name'],
                    'sort_order' => $index + 1,
                    'is_active' => true,
                    'metadata' => null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('reference_catalogs') || ! Schema::hasTable('reference_items')) {
            return;
        }

        $catalogId = DB::table('reference_catalogs')->where('code', 'journal_grade_values')->value('id');
        if ($catalogId) {
            DB::table('reference_items')->where('catalog_id', $catalogId)->delete();
            DB::table('reference_catalogs')->where('id', $catalogId)->delete();
        }
    }
};
