<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE teaching_loads ALTER COLUMN teacher_id DROP NOT NULL');
        }

        Schema::table('teaching_loads', function (Blueprint $table): void {
            if (! Schema::hasColumn('teaching_loads', 'curriculum_id')) {
                $table->foreignId('curriculum_id')->nullable()->after('teacher_id')->constrained('curricula')->nullOnDelete();
            }
            if (! Schema::hasColumn('teaching_loads', 'group_id')) {
                $table->foreignId('group_id')->nullable()->after('curriculum_id')->constrained('groups')->nullOnDelete();
            }
            if (! Schema::hasColumn('teaching_loads', 'generated_at')) {
                $table->timestamp('generated_at')->nullable()->after('description');
            }
            if (! Schema::hasColumn('teaching_loads', 'generated_by')) {
                $table->foreignId('generated_by')->nullable()->after('generated_at')->constrained('users')->nullOnDelete();
            }
        });

        Schema::table('teaching_load_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('teaching_load_items', 'curriculum_subject_id')) {
                $table->foreignId('curriculum_subject_id')->nullable()->after('teaching_load_id')->constrained('curriculum_subjects')->nullOnDelete();
            }
            if (! Schema::hasColumn('teaching_load_items', 'teacher_id')) {
                $table->foreignId('teacher_id')->nullable()->after('group_id')->constrained('teachers')->nullOnDelete();
            }
            if (! Schema::hasColumn('teaching_load_items', 'planned_hours')) {
                $table->unsignedSmallInteger('planned_hours')->default(0)->after('hours_total');
            }
            if (! Schema::hasColumn('teaching_load_items', 'assigned_hours')) {
                $table->unsignedSmallInteger('assigned_hours')->default(0)->after('planned_hours');
            }
            if (! Schema::hasColumn('teaching_load_items', 'unassigned_hours')) {
                $table->unsignedSmallInteger('unassigned_hours')->default(0)->after('assigned_hours');
            }
            if (! Schema::hasColumn('teaching_load_items', 'overassigned_hours')) {
                $table->unsignedSmallInteger('overassigned_hours')->default(0)->after('unassigned_hours');
            }
            if (! Schema::hasColumn('teaching_load_items', 'workload_type_id')) {
                $table->foreignId('workload_type_id')->nullable()->after('load_type')->constrained('reference_items')->nullOnDelete();
            }
            if (! Schema::hasColumn('teaching_load_items', 'assignment_status')) {
                $table->string('assignment_status')->default('unassigned')->after('workload_type_id')->index();
            }
            if (! Schema::hasColumn('teaching_load_items', 'source')) {
                $table->string('source')->default('manual')->after('assignment_status')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('teaching_load_items', function (Blueprint $table): void {
            foreach (['source', 'assignment_status', 'workload_type_id', 'overassigned_hours', 'unassigned_hours', 'assigned_hours', 'planned_hours', 'teacher_id', 'curriculum_subject_id'] as $column) {
                if (Schema::hasColumn('teaching_load_items', $column)) {
                    if (in_array($column, ['workload_type_id', 'teacher_id', 'curriculum_subject_id'], true)) {
                        $table->dropConstrainedForeignId($column);
                    } else {
                        $table->dropColumn($column);
                    }
                }
            }
        });

        Schema::table('teaching_loads', function (Blueprint $table): void {
            foreach (['generated_by', 'generated_at', 'group_id', 'curriculum_id'] as $column) {
                if (Schema::hasColumn('teaching_loads', $column)) {
                    if (in_array($column, ['generated_by', 'group_id', 'curriculum_id'], true)) {
                        $table->dropConstrainedForeignId($column);
                    } else {
                        $table->dropColumn($column);
                    }
                }
            }
        });
    }
};
