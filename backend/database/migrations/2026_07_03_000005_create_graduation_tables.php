<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('graduates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('education_program_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('specialty_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('graduation_year')->index();
            $table->string('qualification')->nullable();
            $table->string('status', 40)->default('draft')->index();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique('student_id');
            $table->index(['group_id', 'graduation_year']);
        });

        Schema::create('diplomas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('graduate_id')->constrained()->cascadeOnDelete();
            $table->string('series')->nullable();
            $table->string('number')->nullable();
            $table->string('registration_number')->nullable()->index();
            $table->date('issue_date')->nullable();
            $table->string('qualification')->nullable();
            $table->string('gia_decision')->nullable();
            $table->string('status', 40)->default('draft')->index();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['series', 'number']);
        });

        Schema::create('diploma_supplements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('diploma_id')->constrained()->cascadeOnDelete();
            $table->string('series')->nullable();
            $table->string('number')->nullable();
            $table->date('issue_date')->nullable();
            $table->string('status', 40)->default('draft')->index();
            $table->json('subjects')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diploma_supplements');
        Schema::dropIfExists('diplomas');
        Schema::dropIfExists('graduates');
    }
};
