<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `NOTIFY-001`: подписки на уведомления, журнал отправок и место для диалога с ботом.
 *
 * Три решения, заметные в схеме.
 *
 * **`chat_id` дописывается к `user_identities`, а не заводит свою таблицу привязок.**
 * Связь «человек — внешний аккаунт» уже описана слоем `AUTH-005`; вторая таблица про то
 * же самое разошлась бы с первой на первом же расхождении. Колонка пустая до тех пор,
 * пока человек не нажмёт «Старт» у бота: **бот не может написать первым**, и без диалога
 * адресовать отправку некуда.
 *
 * **Подписка — строка на человека и событие, а не колонка на каждую галочку.** Состав
 * событий будет меняться; миграция на каждую новую галочку — нет.
 *
 * **Журнал отправок не хранит текст сообщения.** В тексте персональные данные, а второй
 * их экземпляр порталу незачем: чтобы ответить на «мне не пришло», хватает события,
 * адресата, времени и исхода.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_identities', function (Blueprint $table): void {
            $table->string('chat_id')->nullable()->after('provider_user_id');
            $table->timestamp('chat_started_at')->nullable()->after('chat_id');
        });

        Schema::create('notification_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('event');
            $table->string('channel');
            $table->timestamps();

            // Одна галочка — одна строка. Повторная отметка не должна плодить дубли
            // и рассылать одно и то же дважды.
            $table->unique(['user_id', 'event', 'channel']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('event')->index();
            $table->string('channel');
            // Ключ повтора: по нему видно, что это же уведомление уже уходило.
            // «Занятия на завтра» за 2026-09-01 отправляются один раз, сколько бы
            // раз ни сработал планировщик.
            $table->string('dedupe_key');
            $table->string('status');
            $table->string('failure_reason')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'channel', 'dedupe_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_subscriptions');

        Schema::table('user_identities', function (Blueprint $table): void {
            $table->dropColumn(['chat_id', 'chat_started_at']);
        });
    }
};
