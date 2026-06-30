<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specialties', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('education_level')->default('Среднее профессиональное образование');
            $table->string('qualification')->nullable();
            $table->decimal('normative_study_years', 3, 1)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('education_programs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('specialty_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('year_start');
            $table->string('study_form')->default('Очная');
            $table->decimal('study_years', 3, 1)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['specialty_id', 'name', 'year_start', 'study_form']);
        });

        Schema::table('groups', function (Blueprint $table): void {
            $table->foreignId('education_program_id')
                ->nullable()
                ->after('specialty')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('education_program_id');
        });

        Schema::dropIfExists('education_programs');
        Schema::dropIfExists('specialties');
    }
};
