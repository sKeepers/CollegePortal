<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_entry_id')->nullable()->constrained('schedule_entries')->nullOnDelete();
            $table->foreignId('legacy_schedule_lesson_id')->nullable()->constrained('schedule_lessons')->nullOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('teacher_id')->constrained()->restrictOnDelete();
            $table->date('lesson_date');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->foreignId('lesson_type_id')->nullable()->constrained('reference_items')->nullOnDelete();
            $table->text('topic')->nullable();
            $table->text('homework')->nullable();
            $table->text('teacher_comment')->nullable();
            $table->string('status')->default('planned');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->foreignId('signed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('schedule_entry_id');
            $table->index(['teacher_id', 'lesson_date']);
            $table->index(['group_id', 'lesson_date']);
            $table->index(['status', 'lesson_date']);
        });

        Schema::create('journal_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_lesson_id')->constrained('journal_lessons')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('present');
            $table->unsignedSmallInteger('minutes_late')->nullable();
            $table->text('comment')->nullable();
            $table->string('source')->default('manual');
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('marked_at')->nullable();
            $table->timestamps();

            $table->unique(['journal_lesson_id', 'student_id']);
            $table->index(['student_id', 'status']);
        });

        Schema::create('journal_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_lesson_id')->constrained('journal_lessons')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grade_type_id')->nullable()->constrained('reference_items')->nullOnDelete();
            $table->string('value')->nullable();
            $table->decimal('weight', 5, 2)->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('marked_at')->nullable();
            $table->timestamps();

            $table->unique(['journal_lesson_id', 'student_id', 'grade_type_id'], 'journal_grades_lesson_student_type_unique');
            $table->index(['student_id']);
        });

        Schema::create('journal_lesson_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_lesson_id')->constrained('journal_lessons')->cascadeOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $this->seedJournalPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lesson_files');
        Schema::dropIfExists('journal_grades');
        Schema::dropIfExists('journal_attendance');
        Schema::dropIfExists('journal_lessons');
    }

    private function seedJournalPermissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissions = [
            ['module' => 'Journal', 'code' => 'journal.attendance', 'name' => 'Журнал: посещаемость', 'description' => 'Ведение посещаемости занятия.', 'system' => true, 'active' => true],
            ['module' => 'Journal', 'code' => 'journal.grades', 'name' => 'Журнал: оценки', 'description' => 'Выставление оценок занятия.', 'system' => true, 'active' => true],
            ['module' => 'Journal', 'code' => 'journal.files', 'name' => 'Журнал: материалы', 'description' => 'Загрузка и удаление материалов занятия.', 'system' => true, 'active' => true],
            ['module' => 'Journal', 'code' => 'journal.complete', 'name' => 'Журнал: завершение', 'description' => 'Завершение занятия журнала.', 'system' => true, 'active' => true],
            ['module' => 'Journal', 'code' => 'journal.sign', 'name' => 'Журнал: подпись', 'description' => 'Подписание занятия журнала.', 'system' => true, 'active' => true],
            ['module' => 'Journal', 'code' => 'journal.reopen', 'name' => 'Журнал: исправление подписанного', 'description' => 'Исправление подписанных занятий.', 'system' => true, 'active' => true],
            ['module' => 'Journal', 'code' => 'journal.view_all', 'name' => 'Журнал: просмотр всех занятий', 'description' => 'Просмотр журналов всех преподавателей и групп.', 'system' => true, 'active' => true],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $permission['code']],
                array_merge($permission, ['created_at' => now(), 'updated_at' => now()]),
            );
        }

        $rolePermissions = [
            'admin' => array_column($permissions, 'code'),
            'director' => ['journal.view_all'],
            'deputy' => ['journal.attendance', 'journal.grades', 'journal.files', 'journal.complete', 'journal.sign', 'journal.reopen', 'journal.view_all'],
            'study' => ['journal.attendance', 'journal.grades', 'journal.files', 'journal.complete', 'journal.sign', 'journal.reopen', 'journal.view_all'],
            'teacher' => ['journal.attendance', 'journal.grades', 'journal.files', 'journal.complete', 'journal.sign'],
        ];

        foreach ($rolePermissions as $roleCode => $codes) {
            $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
            if (! $roleId) {
                continue;
            }
            $permissionIds = DB::table('permissions')->whereIn('code', $codes)->pluck('id');
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    []
                );
            }
        }
    }
};
