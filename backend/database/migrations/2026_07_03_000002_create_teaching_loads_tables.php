<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teaching_loads', function (Blueprint $table): void {
            $table->id();
            $table->string('academic_year')->index();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('draft')->index();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->index(['academic_year', 'teacher_id']);
        });

        Schema::create('teaching_load_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('teaching_load_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('semester')->index();
            $table->unsignedSmallInteger('hours_total')->default(0);
            $table->string('load_type')->default('Аудиторная');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['teaching_load_id', 'subject_id', 'group_id', 'semester', 'load_type'], 'teaching_load_unique_item');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teaching_load_items');
        Schema::dropIfExists('teaching_loads');
    }
};
