<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_lessons', function (Blueprint $table): void {
            if (! Schema::hasColumn('journal_lessons', 'homework_due_at')) {
                $table->dateTime('homework_due_at')->nullable()->after('homework');
            }
            if (! Schema::hasColumn('journal_lessons', 'reopened_at')) {
                $table->timestamp('reopened_at')->nullable()->after('signed_by');
            }
            if (! Schema::hasColumn('journal_lessons', 'reopened_by')) {
                $table->foreignId('reopened_by')->nullable()->after('reopened_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('journal_lessons', 'reopen_reason')) {
                $table->text('reopen_reason')->nullable()->after('reopened_by');
            }
        });

        $this->seedJournalGradeValues();
    }

    public function down(): void
    {
        if (Schema::hasTable('reference_catalogs') && Schema::hasTable('reference_items')) {
            $catalogId = DB::table('reference_catalogs')->where('code', 'journal_grade_values')->value('id');
            if ($catalogId) {
                DB::table('reference_items')->where('catalog_id', $catalogId)->delete();
                DB::table('reference_catalogs')->where('id', $catalogId)->delete();
            }
        }

        Schema::table('journal_lessons', function (Blueprint $table): void {
            if (Schema::hasColumn('journal_lessons', 'reopen_reason')) {
                $table->dropColumn('reopen_reason');
            }
            if (Schema::hasColumn('journal_lessons', 'reopened_by')) {
                $table->dropConstrainedForeignId('reopened_by');
            }
            if (Schema::hasColumn('journal_lessons', 'reopened_at')) {
                $table->dropColumn('reopened_at');
            }
            if (Schema::hasColumn('journal_lessons', 'homework_due_at')) {
                $table->dropColumn('homework_due_at');
            }
        });
    }

    private function seedJournalGradeValues(): void
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
};
