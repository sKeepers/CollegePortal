<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Укрепляет жизненный цикл документов Admissions Foundation без изменения legacy `/admissions`.
     */
    public function up(): void
    {
        Schema::table('admission_identity_documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('admission_identity_documents', 'previous_version_id')) {
                $table->foreignId('previous_version_id')->nullable()->after('uuid')->constrained('admission_identity_documents')->nullOnDelete();
            }
            if (! Schema::hasColumn('admission_identity_documents', 'version_number')) {
                $table->unsignedInteger('version_number')->default(1)->after('previous_version_id');
            }
            if (! Schema::hasColumn('admission_identity_documents', 'replaced_by_document_id')) {
                $table->foreignId('replaced_by_document_id')->nullable()->after('archived_by')->constrained('admission_identity_documents')->nullOnDelete();
            }
            if (! Schema::hasColumn('admission_identity_documents', 'replaced_by')) {
                $table->foreignId('replaced_by')->nullable()->after('replaced_by_document_id')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('admission_identity_documents', 'replaced_at')) {
                $table->timestamp('replaced_at')->nullable()->after('replaced_by');
            }

            $table->index(['applicant_id', 'replaced_at', 'archived_at'], 'admission_identity_current_index');
        });

        Schema::table('admission_education_documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('admission_education_documents', 'previous_version_id')) {
                $table->foreignId('previous_version_id')->nullable()->after('uuid')->constrained('admission_education_documents')->nullOnDelete();
            }
            if (! Schema::hasColumn('admission_education_documents', 'version_number')) {
                $table->unsignedInteger('version_number')->default(1)->after('previous_version_id');
            }
            if (! Schema::hasColumn('admission_education_documents', 'qualification_name')) {
                $table->string('qualification_name', 500)->nullable()->after('has_attachment');
            }
            if (! Schema::hasColumn('admission_education_documents', 'speciality_name')) {
                $table->string('speciality_name', 500)->nullable()->after('qualification_name');
            }
            if (! Schema::hasColumn('admission_education_documents', 'registration_number')) {
                $table->string('registration_number', 100)->nullable()->after('speciality_name');
            }
            if (! Schema::hasColumn('admission_education_documents', 'is_nostrificated')) {
                $table->boolean('is_nostrificated')->nullable()->after('registration_number');
            }
            if (! Schema::hasColumn('admission_education_documents', 'replaced_by_document_id')) {
                $table->foreignId('replaced_by_document_id')->nullable()->after('archived_by')->constrained('admission_education_documents')->nullOnDelete();
            }
            if (! Schema::hasColumn('admission_education_documents', 'replaced_by')) {
                $table->foreignId('replaced_by')->nullable()->after('replaced_by_document_id')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('admission_education_documents', 'replaced_at')) {
                $table->timestamp('replaced_at')->nullable()->after('replaced_by');
            }

            $table->index(['applicant_id', 'replaced_at', 'archived_at'], 'admission_education_current_index');
        });

        Schema::create('admission_application_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained('applicant_applications')->cascadeOnDelete();
            $table->foreignId('identity_document_id')->nullable()->constrained('admission_identity_documents')->restrictOnDelete();
            $table->foreignId('education_document_id')->nullable()->constrained('admission_education_documents')->restrictOnDelete();
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('linked_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('identity_document_id', 'admission_application_identity_document_index');
            $table->index('education_document_id', 'admission_application_education_document_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_application_documents');

        Schema::table('admission_education_documents', function (Blueprint $table): void {
            if (Schema::hasColumn('admission_education_documents', 'replaced_at')) {
                $table->dropIndex('admission_education_current_index');
            }
            foreach (['previous_version_id', 'replaced_by_document_id', 'replaced_by'] as $column) {
                if (Schema::hasColumn('admission_education_documents', $column)) {
                    $table->dropForeign([$column]);
                }
            }
            foreach ([
                'replaced_at',
                'replaced_by',
                'replaced_by_document_id',
                'is_nostrificated',
                'registration_number',
                'speciality_name',
                'qualification_name',
                'version_number',
                'previous_version_id',
            ] as $column) {
                if (Schema::hasColumn('admission_education_documents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('admission_identity_documents', function (Blueprint $table): void {
            if (Schema::hasColumn('admission_identity_documents', 'replaced_at')) {
                $table->dropIndex('admission_identity_current_index');
            }
            foreach (['previous_version_id', 'replaced_by_document_id', 'replaced_by'] as $column) {
                if (Schema::hasColumn('admission_identity_documents', $column)) {
                    $table->dropForeign([$column]);
                }
            }
            foreach ([
                'replaced_at',
                'replaced_by',
                'replaced_by_document_id',
                'version_number',
                'previous_version_id',
            ] as $column) {
                if (Schema::hasColumn('admission_identity_documents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
