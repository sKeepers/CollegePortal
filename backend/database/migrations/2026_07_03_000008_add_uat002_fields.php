<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->string('photo_path')->nullable()->after('email');
        });

        Schema::table('teachers', function (Blueprint $table): void {
            $table->string('photo_path')->nullable()->after('email');
        });

        Schema::table('graduates', function (Blueprint $table): void {
            $table->string('photo_path')->nullable()->after('qualification');
        });

        Schema::table('curricula', function (Blueprint $table): void {
            $table->string('code', 100)->nullable()->after('id');
            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::table('curricula', function (Blueprint $table): void {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });

        Schema::table('graduates', function (Blueprint $table): void {
            $table->dropColumn('photo_path');
        });

        Schema::table('teachers', function (Blueprint $table): void {
            $table->dropColumn('photo_path');
        });

        Schema::table('students', function (Blueprint $table): void {
            $table->dropColumn('photo_path');
        });
    }
};
