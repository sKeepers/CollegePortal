<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('present');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['schedule_lesson_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance');
    }
};
