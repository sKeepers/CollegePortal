<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Привязка мессенджера к учётной записи и указатель очереди обновлений бота.
 *
 * **Зачем код, а не просто «Старт».** Бот видит, что ему написал человек с таким-то
 * идентификатором в MAX, но кто это в портале — не знает и знать не может. Связывает
 * их одноразовый код: портал показывает его вошедшему человеку, человек отправляет его
 * боту. Кода нет — привязки нет, и подобрать его нельзя: он живёт минуты и одноразовый.
 *
 * **Указатель очереди — отдельная строка, а не настройка.** Это рабочее состояние
 * процесса, а не то, что администратор правит на экране настроек. Очередь у бота одна,
 * читается по указателю, поэтому забирать её должен ровно один процесс.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_link_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel');
            $table->string('code');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            // Ищем всегда по коду и каналу — по ним же и уникальность, чтобы два
            // человека не получили один код одновременно.
            $table->unique(['channel', 'code']);
        });

        Schema::create('notification_channel_cursors', function (Blueprint $table): void {
            $table->id();
            $table->string('channel')->unique();
            $table->unsignedBigInteger('marker')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_channel_cursors');
        Schema::dropIfExists('notification_link_codes');
    }
};
