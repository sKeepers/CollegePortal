<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('data_type', 40)->index();
            $table->string('mode', 40)->default('create')->index();
            $table->string('status', 40)->default('preview')->index();
            $table->string('original_filename')->nullable();
            $table->string('stored_path')->nullable();
            $table->json('headers')->nullable();
            $table->json('mapping')->nullable();
            $table->json('preview_rows')->nullable();
            $table->json('validation_errors')->nullable();
            $table->json('result')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->timestamps();

            $table->index(['data_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_jobs');
    }
};
