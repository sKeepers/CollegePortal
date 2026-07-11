<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curricula', function (Blueprint $table): void {
            if (! Schema::hasColumn('curricula', 'qualification')) {
                $table->string('qualification')->nullable()->after('name');
            }
            if (! Schema::hasColumn('curricula', 'competencies')) {
                $table->json('competencies')->nullable()->after('description');
            }
        });

        Schema::create('curriculum_subjects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('curriculum_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('semester')->index();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('lecture_hours')->default(0);
            $table->unsignedSmallInteger('practice_hours')->default(0);
            $table->unsignedSmallInteger('laboratory_hours')->default(0);
            $table->unsignedSmallInteger('independent_hours')->default(0);
            $table->unsignedSmallInteger('total_hours')->default(0);
            $table->foreignId('control_type_id')->nullable()->constrained('reference_items')->nullOnDelete();
            $table->string('control_type')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->boolean('is_optional')->default(false);
            $table->json('competencies')->nullable();
            $table->timestamps();

            $table->unique(['curriculum_id', 'semester', 'subject_id']);
            $table->index(['curriculum_id', 'semester', 'sequence']);
        });

        Schema::table('groups', function (Blueprint $table): void {
            if (! Schema::hasColumn('groups', 'curriculum_id')) {
                $table->foreignId('curriculum_id')->nullable()->after('education_program_id')->constrained('curricula')->nullOnDelete();
                $table->index(['education_program_id', 'curriculum_id']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            if (Schema::hasColumn('groups', 'curriculum_id')) {
                $table->dropForeign(['curriculum_id']);
                $table->dropIndex(['education_program_id', 'curriculum_id']);
                $table->dropColumn('curriculum_id');
            }
        });

        Schema::dropIfExists('curriculum_subjects');

        Schema::table('curricula', function (Blueprint $table): void {
            if (Schema::hasColumn('curricula', 'competencies')) {
                $table->dropColumn('competencies');
            }
            if (Schema::hasColumn('curricula', 'qualification')) {
                $table->dropColumn('qualification');
            }
        });
    }
};
