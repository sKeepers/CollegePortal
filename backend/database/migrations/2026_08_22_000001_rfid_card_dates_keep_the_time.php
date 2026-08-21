<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Выдача и приём карты запоминают время, а не только день.
 *
 * В журнале время было с самого начала, а в самой карте — нет: `issued_at` и
 * `returned_at` заводились датами. Из-за этого реестр показывал «выдана
 * 21.08.2026» и не мог сказать, утром или вечером, хотя журнал рядом знал час и
 * минуту. Две карты, выданные в один день, в реестре выглядели одинаково.
 *
 * Тип меняется на отметку времени. Уже записанные даты станут полуночью того же
 * дня — это честно: часа для них никто не знал.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfid_cards', function (Blueprint $table): void {
            $table->timestamp('issued_at')->nullable()->change();
            $table->timestamp('returned_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('rfid_cards', function (Blueprint $table): void {
            $table->date('issued_at')->nullable()->change();
            $table->date('returned_at')->nullable()->change();
        });
    }
};
