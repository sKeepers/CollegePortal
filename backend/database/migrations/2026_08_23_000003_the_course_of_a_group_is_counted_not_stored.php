<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Курс группы больше не хранится: он считается из года набора.
 *
 * Хранимый курс — это обещание сдвигать 68 строк каждое лето. Пропущенный сдвиг
 * не падает и не заметен целиком: расписание, ведомости и учебные планы
 * разъезжаются по одному, и находят это в сентябре.
 *
 * Год набора для счёта есть у всех групп и был обязателен всегда, так что
 * терять нечего. Замер на стенде 23.08.2026: у всех 68 групп хранившийся курс
 * совпадал с вычисленным — 2023→4, 2024→3, 2025→2, 2026→1.
 *
 * Обратная миграция восстанавливает столбец и заполняет его тем же счётом.
 * Отдельного «того, что было» у неё нет и быть не может: значения совпадали.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn('course');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->unsignedTinyInteger('course')->default(1)->after('curriculum_id');
        });

        $academicYear = (int) date('n') >= 8 ? (int) date('Y') : (int) date('Y') - 1;

        // Год набора обязателен, поэтому под условие попадает каждая строка.
        \Illuminate\Support\Facades\DB::table('groups')->update([
            'course' => \Illuminate\Support\Facades\DB::raw('GREATEST(1, '.$academicYear.' - year_start + 1)'),
        ]);
    }
};
