<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('classroom_id')->nullable()->constrained()->nullOnDelete();
            $table->date('lesson_date');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('lesson_type')->default('lesson');
            $table->string('topic')->nullable();
            $table->timestamps();

            $table->index(['group_id', 'lesson_date', 'starts_at', 'ends_at']);
            $table->index(['teacher_id', 'lesson_date', 'starts_at', 'ends_at']);
            $table->index(['classroom_id', 'lesson_date', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_lessons');
    }
};
