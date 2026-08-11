<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Признак «пароль выдан порталом, свой ещё не заведён».
 *
 * Без него вопрос «кто до сих пор ходит с выданным паролем» не имеет ответа: в базе
 * лежит хеш, и по нему длину не узнать. Отметку ставит тот, кто пароль выдал —
 * создание учётной записи и сброс администратором, — а снимает сам человек, когда
 * заводит свой в «Моей учётной записи».
 *
 * Значение по умолчанию `false`, и существующие записи получают именно его. Поставить
 * всем `true` значило бы предложить сменить пароль и тем, кто его уже сменил: кто это
 * сделал, портал не знает — см. выше про хеш. Что делать с уже выданными паролями,
 * решает владелец, разбор в docs/PASSWORD_POLICY_DECISION.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('must_change_password')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('must_change_password');
        });
    }
};
