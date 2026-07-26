<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('applicant_applications')) {
            return;
        }

        Schema::table('applicant_applications', function (Blueprint $table): void {
            if (! Schema::hasColumn('applicant_applications', 'record_type')) {
                $table->string('record_type', 40)->default('legacy')->after('uuid');
            }

            if (! Schema::hasColumn('applicant_applications', 'foundation_version')) {
                $table->unsignedSmallInteger('foundation_version')->nullable()->after('record_type');
            }
        });

        DB::table('applicant_applications')
            ->whereNotNull('applicant_id')
            ->update([
                'record_type' => 'foundation',
                'foundation_version' => 1,
            ]);

        DB::table('applicant_applications')
            ->whereNull('applicant_id')
            ->where(function ($query): void {
                $query->whereNull('record_type')->orWhere('record_type', '');
            })
            ->update(['record_type' => 'legacy']);

        Schema::table('applicant_applications', function (Blueprint $table): void {
            $table->index('record_type', 'applicant_applications_record_type_index');
            $table->index(['record_type', 'applicant_id'], 'applicant_applications_record_type_applicant_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('applicant_applications')) {
            return;
        }

        Schema::table('applicant_applications', function (Blueprint $table): void {
            foreach ([
                'applicant_applications_record_type_applicant_index',
                'applicant_applications_record_type_index',
            ] as $index) {
                try {
                    $table->dropIndex($index);
                } catch (\Throwable) {
                }
            }
        });

        Schema::table('applicant_applications', function (Blueprint $table): void {
            foreach (['foundation_version', 'record_type'] as $column) {
                if (Schema::hasColumn('applicant_applications', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
