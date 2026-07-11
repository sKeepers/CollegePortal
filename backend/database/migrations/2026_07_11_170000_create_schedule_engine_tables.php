<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_entries', function (Blueprint $table) {
            $table->id();
            $table->string('academic_year');
            $table->unsignedTinyInteger('semester');
            $table->date('date')->nullable();
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->string('week_type')->nullable();
            $table->unsignedTinyInteger('lesson_number');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('teacher_id')->constrained()->restrictOnDelete();
            $table->foreignId('classroom_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('teaching_load_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lesson_type_id')->nullable()->constrained('reference_items')->nullOnDelete();
            $table->string('status')->default('draft');
            $table->string('source')->default('manual');
            $table->boolean('is_replacement')->default(false);
            $table->foreignId('replaced_entry_id')->nullable()->constrained('schedule_entries')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['academic_year', 'semester']);
            $table->index(['date', 'starts_at', 'ends_at']);
            $table->index(['group_id', 'date', 'starts_at', 'ends_at']);
            $table->index(['teacher_id', 'date', 'starts_at', 'ends_at']);
            $table->index(['classroom_id', 'date', 'starts_at', 'ends_at']);
            $table->index(['teaching_load_item_id']);
        });

        Schema::table('schedule_lessons', function (Blueprint $table) {
            $table->foreignId('schedule_entry_id')->nullable()->after('id')->constrained('schedule_entries')->nullOnDelete();
        });

        Schema::create('schedule_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('academic_year');
            $table->unsignedTinyInteger('semester');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->foreignId('group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('week_type')->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('schedule_template_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->string('week_type')->nullable();
            $table->unsignedTinyInteger('lesson_number');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('teacher_id')->constrained()->restrictOnDelete();
            $table->foreignId('classroom_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('teaching_load_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lesson_type_id')->nullable()->constrained('reference_items')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_template_entries');
        Schema::dropIfExists('schedule_templates');
        Schema::table('schedule_lessons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('schedule_entry_id');
        });
        Schema::dropIfExists('schedule_entries');
    }
};
