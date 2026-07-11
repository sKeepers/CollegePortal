<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            if (! Schema::hasColumn('students', 'course')) {
                $table->unsignedTinyInteger('course')->nullable()->after('group_id')->index();
            }

            if (! Schema::hasColumn('students', 'education_form')) {
                $table->string('education_form', 80)->nullable()->after('enrollment_date');
            }

            if (! Schema::hasColumn('students', 'funding_form')) {
                $table->string('funding_form', 80)->nullable()->after('education_form');
            }

            if (! Schema::hasColumn('students', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('funding_form')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            foreach (['archived_at', 'funding_form', 'education_form', 'course'] as $column) {
                if (Schema::hasColumn('students', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
