<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table): void {
            if (! Schema::hasColumn('people', 'place_birth')) {
                $table->string('place_birth')->nullable()->after('citizenship');
            }
            if (! Schema::hasColumn('people', 'address')) {
                $table->text('address')->nullable()->after('email');
            }
        });

        Schema::table('applicant_applications', function (Blueprint $table): void {
            if (! Schema::hasColumn('applicant_applications', 'external_source')) {
                $table->string('external_source', 60)->nullable()->after('person_id');
            }
            if (! Schema::hasColumn('applicant_applications', 'external_application_number')) {
                $table->string('external_application_number')->nullable()->after('external_source');
            }
            if (! Schema::hasColumn('applicant_applications', 'external_status')) {
                $table->string('external_status')->nullable()->after('external_application_number');
            }
            if (! Schema::hasColumn('applicant_applications', 'external_registered_at')) {
                $table->date('external_registered_at')->nullable()->after('external_status');
            }
            if (! Schema::hasColumn('applicant_applications', 'competition_name')) {
                $table->string('competition_name')->nullable()->after('education_program_id');
            }
            if (! Schema::hasColumn('applicant_applications', 'education_form')) {
                $table->string('education_form', 80)->nullable()->after('education_base');
            }
            if (! Schema::hasColumn('applicant_applications', 'funding_form')) {
                $table->string('funding_form', 80)->nullable()->after('education_form');
            }
            if (! Schema::hasColumn('applicant_applications', 'certificate_average_score')) {
                $table->decimal('certificate_average_score', 5, 2)->nullable()->after('funding_form');
            }
            if (! Schema::hasColumn('applicant_applications', 'achievement_score')) {
                $table->decimal('achievement_score', 5, 2)->nullable()->after('certificate_average_score');
            }
            if (! Schema::hasColumn('applicant_applications', 'ranking_score')) {
                $table->decimal('ranking_score', 7, 2)->nullable()->after('achievement_score');
            }
            if (! Schema::hasColumn('applicant_applications', 'documents_provided')) {
                $table->boolean('documents_provided')->nullable()->after('ranking_score');
            }
            if (! Schema::hasColumn('applicant_applications', 'recommended_for_enrollment')) {
                $table->boolean('recommended_for_enrollment')->nullable()->after('documents_provided');
            }
            if (! Schema::hasColumn('applicant_applications', 'fis_raw_data')) {
                $table->json('fis_raw_data')->nullable()->after('recommended_for_enrollment');
            }
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('applicant_applications', function (Blueprint $table): void {
                $table->unique(['external_source', 'external_application_number'], 'applicant_applications_fis_external_unique');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('applicant_applications', function (Blueprint $table): void {
                $table->dropUnique('applicant_applications_fis_external_unique');
            });
        }

        Schema::table('applicant_applications', function (Blueprint $table): void {
            foreach (['fis_raw_data','recommended_for_enrollment','documents_provided','ranking_score','achievement_score','certificate_average_score','funding_form','education_form','competition_name','external_registered_at','external_status','external_application_number','external_source'] as $column) {
                if (Schema::hasColumn('applicant_applications', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('people', function (Blueprint $table): void {
            foreach (['address', 'place_birth'] as $column) {
                if (Schema::hasColumn('people', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
