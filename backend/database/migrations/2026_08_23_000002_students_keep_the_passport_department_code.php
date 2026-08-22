<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Код подразделения, выдавшего паспорт.
 *
 * Остальные реквизиты паспорта у студента уже есть — серия, номер, дата выдачи и
 * кем выдан (`2026_08_03_000003`). Кода подразделения не было, а без него
 * паспортные данные неполны: он нужен и договорам, и пакетам ФИС.
 *
 * **В выгрузках ФИС ГИА за 2023-2026 этот столбец пуст.** Замер 23.08.2026 по
 * четырём файлам владельца: 847 строк, заполнено одно значение. Поле заводится
 * не под эту загрузку, а потому что место для реквизита обязано быть — иначе
 * его некуда положить, когда паспорта начнут вносить руками.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('passport_department_code', 20)->nullable()->after('passport_issued_by');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('passport_department_code');
        });
    }
};
