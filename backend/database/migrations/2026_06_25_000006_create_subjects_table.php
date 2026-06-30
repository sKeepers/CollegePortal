<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable()->unique();
            $table->string('department')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('subject_teacher', function (Blueprint $table) {
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->primary(['subject_id', 'teacher_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_teacher');
        Schema::dropIfExists('subjects');
    }
};
