<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table): void {
            $table->id();
            $table->string('academic_year', 20)->index();
            $table->unsignedTinyInteger('semester')->index();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('teacher_id')->constrained()->restrictOnDelete();
            $table->foreignId('classroom_id')->nullable()->constrained()->nullOnDelete();
            $table->date('exam_date')->index();
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->string('exam_type', 40)->index();
            $table->string('status', 40)->default('scheduled')->index();
            $table->string('topic')->nullable();
            $table->timestamps();

            $table->index(['group_id', 'academic_year']);
            $table->index(['teacher_id', 'exam_date']);
            $table->index(['subject_id', 'academic_year']);
        });

        Schema::create('exam_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('result', 20)->nullable();
            $table->unsignedSmallInteger('score')->nullable();
            $table->string('status', 40)->default('planned')->index();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['exam_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_results');
        Schema::dropIfExists('exams');
    }
};
