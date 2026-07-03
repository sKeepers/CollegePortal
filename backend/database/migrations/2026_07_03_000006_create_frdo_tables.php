<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('frdo_packages', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedSmallInteger('graduation_year')->index();
            $table->foreignId('education_program_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 40)->default('draft')->index();
            $table->timestamp('validation_checked_at')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('frdo_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('frdo_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('graduate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('diploma_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('diploma_supplement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('education_program_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('specialty_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 40)->default('draft')->index();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['frdo_package_id', 'graduate_id']);
        });

        Schema::create('frdo_validation_errors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('frdo_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('frdo_record_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('field')->nullable();
            $table->string('message');
            $table->string('severity', 20)->default('error');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('frdo_validation_errors');
        Schema::dropIfExists('frdo_records');
        Schema::dropIfExists('frdo_packages');
    }
};
