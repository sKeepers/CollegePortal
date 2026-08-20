<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Номер личного дела студента.
 *
 * В бумажных списках колледжа это столбец «Алфавитный классификатор» с кодом
 * формы `02-20`. Владелец 21.08.2026: номер адресного классификатора привязан к
 * номеру дела и к номеру зачётной книжки — то есть это один и тот же номер, а
 * не три разных.
 *
 * **Уникальным он не объявляется, и это осознанно.** Замер по «Спискам
 * студентов 2026-2027»: номер стоит у 591 записи из 593, значения 4..601,
 * различных 428, повторов 109 — десяток повторов встречается даже внутри одной
 * таблицы группы. Уникальный индекс на таких данных просто не соберётся, а
 * загрузка контингента упала бы на середине. В каких границах номер обязан быть
 * уникальным, владелец уточняет в учебной части; индекс добавится отдельной
 * миграцией, когда ответ будет.
 *
 * Индекс здесь обычный: по номеру ищут студента, и поиск не должен идти
 * перебором.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('personal_file_number', 50)->nullable()->after('enrollment_order_date');
            $table->index('personal_file_number');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex(['personal_file_number']);
            $table->dropColumn('personal_file_number');
        });
    }
};
