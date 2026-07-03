<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fis_packages', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('package_type', 40)->index();
            $table->unsignedSmallInteger('year')->index();
            $table->foreignId('education_program_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 40)->default('draft')->index();
            $table->timestamp('validation_checked_at')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('fis_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fis_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('applicant_application_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('graduate_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('education_program_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('specialty_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 40)->default('draft')->index();
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::create('fis_validation_errors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fis_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fis_record_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('field')->nullable();
            $table->string('message');
            $table->string('severity', 20)->default('error');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fis_validation_errors');
        Schema::dropIfExists('fis_records');
        Schema::dropIfExists('fis_packages');
    }
};
