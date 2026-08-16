<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Два добавления, оба следуют из решений владельца.
 *
 * **`subject_user_id` — о ком уведомление.** До сих пор подписка означала «пишите мне
 * обо мне»: получатель и предмет совпадали. Решение владельца — родитель получает
 * уведомления о студенте — их разводит: получатель один, предмет другой. Без этой
 * колонки родительская подписка была бы неотличима от собственной, и студент не смог
 * бы увидеть, что о нём пишут кому-то ещё.
 *
 * Значение по умолчанию — сам получатель: все существующие подписки собственные, и
 * заполнять их отдельно не нужно.
 *
 * **`next_attempt_at` — время следующей попытки.** Мессенджер отвечает отказом чаще,
 * чем кажется: человек удалил бота, заблокировал, сменил аккаунт, сеть моргнула.
 * Без повторов первая же неудача теряет уведомление навсегда, а без отметки времени
 * повтор пришлось бы делать сразу же — и он упёрся бы в ту же недоступность.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_subscriptions', function (Blueprint $table): void {
            $table->foreignId('subject_user_id')->nullable()->after('user_id')->constrained('users')->cascadeOnDelete();
        });

        // Существующие подписки — собственные: получатель и есть предмет.
        \Illuminate\Support\Facades\DB::table('notification_subscriptions')
            ->whereNull('subject_user_id')
            ->update(['subject_user_id' => \Illuminate\Support\Facades\DB::raw('user_id')]);

        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->timestamp('next_attempt_at')->nullable()->after('attempts')->index();
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropColumn('next_attempt_at');
        });

        Schema::table('notification_subscriptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('subject_user_id');
        });
    }
};
