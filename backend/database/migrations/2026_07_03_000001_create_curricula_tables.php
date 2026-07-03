<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curricula', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('education_program_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->integer('year_start')->index();
            $table->string('status')->default('draft')->index();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->index(['education_program_id', 'year_start']);
        });

        Schema::create('curriculum_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('curriculum_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('course')->index();
            $table->unsignedTinyInteger('semester')->index();
            $table->unsignedSmallInteger('hours_total')->default(0);
            $table->string('control_form')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['curriculum_id', 'subject_id', 'course', 'semester']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_items');
        Schema::dropIfExists('curricula');
    }
};
