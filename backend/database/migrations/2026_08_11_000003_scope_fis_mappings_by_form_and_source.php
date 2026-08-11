<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Конкурс ФИС — свойство условия приёма, а не программы.
 *
 * У одной образовательной программы конкурсов бывает несколько сразу: бюджет и
 * платное, очное и заочное. Сопоставление хранило один идентификатор на
 * программу и окружение, поэтому неоднозначные случаи не связывались вовсе, а
 * `ApplicationsWriter` без `CompetitiveGroupUID` останавливал сборку. По таким
 * программам — самым обычным, где принимают и на бюджет, и на платное, — нельзя
 * было отправить ни одного заявления.
 *
 * Ключ сопоставления получает четвёртую часть: форму обучения и источник
 * финансирования, как их понимает сама ФИС в `FinSourceEduForm`.
 *
 * Пустая строка, а не NULL, намеренно: в уникальном индексе PostgreSQL и SQLite
 * считают NULL несовпадающими друг с другом, и уникальность на прежних записях
 * просто перестала бы работать.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fis_external_mappings', function (Blueprint $table): void {
            $table->string('scope')->default('')->after('external_id');
        });

        Schema::table('fis_external_mappings', function (Blueprint $table): void {
            $table->dropUnique('fis_external_mappings_unique');
            $table->unique(
                ['entity_type', 'entity_id', 'external_type', 'environment', 'scope'],
                'fis_external_mappings_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('fis_external_mappings', function (Blueprint $table): void {
            $table->dropUnique('fis_external_mappings_unique');
        });

        // Обратный ход теряет разведение по условиям приёма: под прежний ключ
        // помещается одна строка на программу. Оставляем ту, что без области —
        // прежнее поведение, — а если такой нет, самую раннюю из областных.
        $duplicates = \Illuminate\Support\Facades\DB::table('fis_external_mappings')
            ->select('entity_type', 'entity_id', 'external_type', 'environment')
            ->groupBy('entity_type', 'entity_id', 'external_type', 'environment')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicates as $group) {
            $rows = \Illuminate\Support\Facades\DB::table('fis_external_mappings')
                ->where('entity_type', $group->entity_type)
                ->where('entity_id', $group->entity_id)
                ->where('external_type', $group->external_type)
                ->where('environment', $group->environment)
                ->orderByRaw("case when scope = '' then 0 else 1 end")
                ->orderBy('id')
                ->get();

            \Illuminate\Support\Facades\DB::table('fis_external_mappings')
                ->whereIn('id', $rows->skip(1)->pluck('id'))
                ->delete();
        }

        Schema::table('fis_external_mappings', function (Blueprint $table): void {
            $table->unique(
                ['entity_type', 'entity_id', 'external_type', 'environment'],
                'fis_external_mappings_unique',
            );
            $table->dropColumn('scope');
        });
    }
};
