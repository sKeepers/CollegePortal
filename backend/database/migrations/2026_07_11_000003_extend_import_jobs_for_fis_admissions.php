<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_jobs', function (Blueprint $table): void {
            if (! Schema::hasColumn('import_jobs', 'source')) {
                $table->string('source', 60)->nullable()->after('data_type')->index();
            }
            if (! Schema::hasColumn('import_jobs', 'file_hash')) {
                $table->string('file_hash', 64)->nullable()->after('stored_path')->index();
            }
            if (! Schema::hasColumn('import_jobs', 'metadata')) {
                $table->json('metadata')->nullable()->after('mapping');
            }
            if (! Schema::hasColumn('import_jobs', 'warnings')) {
                $table->json('warnings')->nullable()->after('validation_errors');
            }
            if (! Schema::hasColumn('import_jobs', 'errors')) {
                $table->json('errors')->nullable()->after('warnings');
            }
        });
    }

    public function down(): void
    {
        Schema::table('import_jobs', function (Blueprint $table): void {
            foreach (['errors', 'warnings', 'metadata', 'file_hash', 'source'] as $column) {
                if (Schema::hasColumn('import_jobs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
