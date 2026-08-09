<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Единица отчётности ФИС ГИА — результат конкретного студента, а не экзамен.
     * До этой миграции запись пакета ссылалась только на экзамен и на одного
     * произвольного выпускника группы, поэтому и человек, и его результат в
     * пакет не попадали вовсе.
     */
    public function up(): void
    {
        Schema::table('fis_records', function (Blueprint $table): void {
            if (! Schema::hasColumn('fis_records', 'student_id')) {
                $table->foreignId('student_id')->nullable()->after('exam_id')->constrained()->cascadeOnDelete();
            }
            if (! Schema::hasColumn('fis_records', 'exam_result_id')) {
                $table->foreignId('exam_result_id')->nullable()->after('student_id')->constrained('exam_results')->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('fis_records', function (Blueprint $table): void {
            foreach (['exam_result_id', 'student_id'] as $column) {
                if (Schema::hasColumn('fis_records', $column)) {
                    $table->dropForeign([$column]);
                    $table->dropColumn($column);
                }
            }
        });
    }
};
