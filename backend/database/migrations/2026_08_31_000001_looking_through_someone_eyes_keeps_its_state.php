<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Взгляд глазами человека: где хранится состояние и чем он оставляет след.
 *
 * Две колонки, и обе нужны по разным причинам.
 *
 * `users.viewing_as_user_id` — само состояние. Не cookie и не заголовок:
 * cookie требует участия клиента, её же можно подделать, и она протекала бы в
 * контур «токен в заголовке», которым ходят скрипты. Колонка вдобавок отвечает
 * на вопрос «кто прямо сейчас смотрит чужими глазами» одним запросом, а не
 * разбором логов.
 *
 * `audit_logs.viewed_as_user_id` — то, ради чего вся затея остаётся честной.
 * Действующим в журнале **всегда** записывается настоящий администратор, а
 * просматриваемый идёт сюда, отдельно. Взять действующего из
 * `$request->user()` после подмены значило бы научить журнал врать ровно там,
 * где он нужен больше всего — и ровно поэтому владелец отверг режим с правкой.
 *
 * Обе связи `nullOnDelete`: удаление учётной записи не должно ни ронять
 * удаление, ни стирать запись журнала. Запись без просматриваемого читается
 * как «смотрели того, кого больше нет», и это лучше, чем её отсутствие.
 *
 * Разбор целиком — `docs/VIEW_AS_PERSON.md`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('viewing_as_user_id')
                ->nullable()
                ->after('person_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->foreignId('viewed_as_user_id')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('viewed_as_user_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('viewing_as_user_id');
        });
    }
};
