<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Связь учётной записи портала с внешним аккаунтом — Telegram, MAX и что появится дальше.
 *
 * Таблица общая для всех провайдеров намеренно: иначе каждый следующий вход напишут
 * по-своему, и второй придётся переписывать. Сами провайдеры — задачи `AUTH-003` и
 * `AUTH-004`; здесь только место, куда они кладут результат.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider')->index();
            $table->string('provider_user_id');
            // Что показать человеку, чтобы он узнал свой аккаунт: @имя или телефон.
            // Не идентификатор и не секрет — только для интерфейса.
            $table->string('display_name')->nullable();
            $table->timestamp('linked_at');
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Один внешний аккаунт не может принадлежать двум учётным записям — иначе
            // вход через мессенджер становится способом попасть в чужую.
            $table->unique(['provider', 'provider_user_id']);
            // И один Telegram на человека, а не пять.
            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_identities');
    }
};
