<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table): void {
            if (! Schema::hasColumn('permissions', 'module')) {
                $table->string('module')->default('System')->after('name');
            }
            if (! Schema::hasColumn('permissions', 'system')) {
                $table->boolean('system')->default(true)->after('description');
            }
            if (! Schema::hasColumn('permissions', 'active')) {
                $table->boolean('active')->default(true)->after('system');
            }
        });
    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table): void {
            if (Schema::hasColumn('permissions', 'active')) {
                $table->dropColumn('active');
            }
            if (Schema::hasColumn('permissions', 'system')) {
                $table->dropColumn('system');
            }
            if (Schema::hasColumn('permissions', 'module')) {
                $table->dropColumn('module');
            }
        });
    }
};
