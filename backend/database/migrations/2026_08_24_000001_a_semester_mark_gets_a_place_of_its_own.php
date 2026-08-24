<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Итоговая оценка за семестр получает своё место.
 *
 * До сих пор её негде было хранить, и это не мелочь: приложение к диплому собирается
 * именно из неё, а не из журнала. Разбор 24.08.2026 (`docs/EXAMS_AND_GIA_REVIEW_2026-08-24.md`)
 * показал три источника, и ни один не годится:
 *
 * - `journal_grades` — оценка **за занятие**, их за семестр десятки на дисциплину;
 * - `exam_results` — оценка **за экзамен**, а дисциплина, кончающаяся зачётом, экзамена
 *   не имеет вовсе, и её итог взять негде ни при каком желании;
 * - средний балл с экрана куратора — **не оценка**: итоговую ставит преподаватель, а не
 *   калькулятор, и у неё бывает и округление в пользу студента, и учёт работы, которой
 *   в журнале нет.
 *
 * Ключ — студент, дисциплина, учебный год и семестр. Год отдельно от семестра намеренно:
 * одна и та же дисциплина идёт в разных курсах, и «второй семестр» сам по себе ничего не
 * значит.
 *
 * `curriculum_subject_id` не обязателен. Он нужен приложению к диплому — оттуда берутся
 * часы и форма контроля, — но учебные планы приходят позже оценок, и ждать их значило бы
 * не собрать первый семестр вовсе.
 *
 * Право одно, на выставление: смотреть ведомость можно с `journal.view`, как и остальной
 * журнал. Заводится **и миграцией, и в `RoleSeeder`** — иначе на установленной системе его
 * не будет, а на новой оно сотрётся: сидер раздаёт права через `sync()`.
 *
 * Идемпотентна: повторный запуск ничего не добавляет.
 */
return new class extends Migration
{
    private const CODE = 'journal.semester_grades';

    /**
     * Те же роли, что и у оценки за занятие (`journal.grades`), плюс администратор.
     *
     * «Учебной части 1» здесь нет намеренно: за ней расписание, нагрузка и планы, а
     * успеваемость — за «Учебной частью 2». Куратор есть, потому что `RoleSeeder` отдаёт
     * ему весь набор преподавателя, и разойдись миграция с сидером — на установленной
     * системе и на новой получились бы разные права.
     */
    private const ROLES = ['admin', 'study_records', 'deputy', 'academic_office', 'teacher', 'curator'];

    public function up(): void
    {
        if (! Schema::hasTable('semester_grades')) {
            Schema::create('semester_grades', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('student_id')->constrained()->cascadeOnDelete();
                $table->foreignId('subject_id')->constrained()->cascadeOnDelete();

                // Группа записана рядом, а не выводится из студента: студент переходит из
                // группы в группу, а ведомость обязана остаться той, в которой оценку
                // ставили. Без этого прошлогодняя ведомость менялась бы задним числом.
                $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('curriculum_subject_id')->nullable()->constrained()->nullOnDelete();

                $table->string('academic_year', 9);
                $table->unsignedTinyInteger('semester');
                $table->string('control_type', 32)->nullable();

                // Значение строкой: «5», «зачтено», «не аттестован» — это не число, и
                // попытка хранить его числом ломается на первом же зачёте.
                $table->string('value', 32);
                $table->unsignedTinyInteger('score')->nullable();

                $table->foreignId('teacher_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('set_at')->nullable();
                $table->string('source', 16)->default('manual');
                $table->string('comment', 500)->nullable();
                $table->timestamps();

                $table->unique(['student_id', 'subject_id', 'academic_year', 'semester'], 'semester_grades_unique');
                $table->index(['group_id', 'subject_id', 'academic_year', 'semester'], 'semester_grades_sheet');
            });
        }

        $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id');

        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'module' => 'Journal',
                'code' => self::CODE,
                'name' => 'Журнал: итоговая оценка за семестр',
                'description' => 'Выставление итоговой оценки по дисциплине за семестр.',
                'system' => true,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (self::ROLES as $code) {
            $roleId = DB::table('roles')->where('code', $code)->value('id');

            if ($roleId !== null) {
                // insertOrIgnore, а не проверка с последующей вставкой: на PostgreSQL
                // упавший INSERT отравил бы всю транзакцию миграции.
                DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('semester_grades');

        $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id');

        if ($permissionId === null) {
            return;
        }

        DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
