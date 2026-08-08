<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Поднимает паспорт и документ об образовании с уровня абитуриента на уровень человека.
     *
     * До этой миграции документ мог принадлежать только абитуриенту: `applicant_id` был
     * обязателен в обеих таблицах, а у документа об образовании не было `person_id` вовсе.
     * Поэтому студент, не проходивший приёмную комиссию — переведённый, восстановленный
     * или заведённый импортом, — физически не мог иметь ни паспорта, ни документа об
     * образовании. `applicant_id` остаётся как исторический признак происхождения записи.
     */
    public function up(): void
    {
        $this->addPersonColumn('admission_education_documents', 'applicant_id');
        $this->addPersonColumn('admission_document_files', 'applicant_id');

        $this->backfillPersonFromApplicant('admission_education_documents');
        $this->backfillPersonFromApplicant('admission_document_files');

        // Документ, у которого не осталось абитуриента, всё равно должен знать человека:
        // файл может быть привязан к документу, а не к заявлению приёмной комиссии.
        $this->backfillFilePersonFromDocuments();

        foreach (['admission_identity_documents', 'admission_education_documents', 'admission_document_files'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unsignedBigInteger('applicant_id')->nullable()->change();
            });
        }

        Schema::table('admission_education_documents', function (Blueprint $table): void {
            $table->unsignedBigInteger('person_id')->nullable(false)->change();
        });

        Schema::table('admission_education_documents', function (Blueprint $table): void {
            $table->index(['person_id', 'archived_at'], 'admission_education_person_archived_index');
            $table->index(['person_id', 'replaced_at', 'archived_at'], 'admission_education_person_current_index');
        });

        Schema::table('admission_identity_documents', function (Blueprint $table): void {
            $table->index(['person_id', 'replaced_at', 'archived_at'], 'admission_identity_person_current_index');
        });

        Schema::table('admission_document_files', function (Blueprint $table): void {
            $table->index(['person_id', 'archived_at'], 'admission_document_files_person_archived_index');
        });
    }

    public function down(): void
    {
        Schema::table('admission_document_files', function (Blueprint $table): void {
            $table->dropIndex('admission_document_files_person_archived_index');
        });

        Schema::table('admission_identity_documents', function (Blueprint $table): void {
            $table->dropIndex('admission_identity_person_current_index');
        });

        Schema::table('admission_education_documents', function (Blueprint $table): void {
            $table->dropIndex('admission_education_person_archived_index');
            $table->dropIndex('admission_education_person_current_index');
        });

        // Старая схема не умеет хранить документ человека без абитуриента, поэтому такие
        // записи снимаются. Данные приёмной комиссии — всё, у чего есть `applicant_id`, —
        // остаются нетронутыми: их эта чистка не касается.
        $this->dropPersonOnlyRecords();

        foreach (['admission_document_files', 'admission_education_documents', 'admission_identity_documents'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unsignedBigInteger('applicant_id')->nullable(false)->change();
            });
        }

        foreach (['admission_education_documents', 'admission_document_files'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if (Schema::hasColumn($table, 'person_id')) {
                    $blueprint->dropForeign(['person_id']);
                    $blueprint->dropColumn('person_id');
                }
            });
        }
    }

    private function addPersonColumn(string $table, string $after): void
    {
        if (Schema::hasColumn($table, 'person_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($after): void {
            $blueprint->foreignId('person_id')->nullable()->after($after)->constrained('people')->restrictOnDelete();
        });
    }

    private function backfillPersonFromApplicant(string $table): void
    {
        DB::table($table)
            ->whereNull('person_id')
            ->whereNotNull('applicant_id')
            ->update([
                'person_id' => DB::raw('(select person_id from applicants where applicants.id = '.$table.'.applicant_id)'),
            ]);
    }

    private function backfillFilePersonFromDocuments(): void
    {
        DB::table('admission_document_files')
            ->whereNull('person_id')
            ->whereNotNull('identity_document_id')
            ->update([
                'person_id' => DB::raw('(select person_id from admission_identity_documents where admission_identity_documents.id = admission_document_files.identity_document_id)'),
            ]);

        DB::table('admission_document_files')
            ->whereNull('person_id')
            ->whereNotNull('education_document_id')
            ->update([
                'person_id' => DB::raw('(select person_id from admission_education_documents where admission_education_documents.id = admission_document_files.education_document_id)'),
            ]);
    }

    private function dropPersonOnlyRecords(): void
    {
        $identityIds = DB::table('admission_identity_documents')->whereNull('applicant_id')->pluck('id');
        $educationIds = DB::table('admission_education_documents')->whereNull('applicant_id')->pluck('id');

        if ($identityIds->isNotEmpty()) {
            DB::table('admission_application_documents')->whereIn('identity_document_id', $identityIds)->update(['identity_document_id' => null]);
            DB::table('admission_document_files')->whereIn('identity_document_id', $identityIds)->delete();
            DB::table('admission_identity_documents')->whereIn('replaced_by_document_id', $identityIds)->update(['replaced_by_document_id' => null]);
            DB::table('admission_identity_documents')->whereIn('previous_version_id', $identityIds)->update(['previous_version_id' => null]);
            DB::table('admission_identity_documents')->whereIn('id', $identityIds)->delete();
        }

        if ($educationIds->isNotEmpty()) {
            DB::table('admission_application_documents')->whereIn('education_document_id', $educationIds)->update(['education_document_id' => null]);
            DB::table('admission_document_files')->whereIn('education_document_id', $educationIds)->delete();
            DB::table('admission_education_documents')->whereIn('replaced_by_document_id', $educationIds)->update(['replaced_by_document_id' => null]);
            DB::table('admission_education_documents')->whereIn('previous_version_id', $educationIds)->update(['previous_version_id' => null]);
            DB::table('admission_education_documents')->whereIn('id', $educationIds)->delete();
        }

        DB::table('admission_document_files')->whereNull('applicant_id')->delete();
    }
};
