<?php

namespace App\Services\Notifications;

use App\Models\NotificationDelivery;
use App\Models\NotificationSubscription;
use App\Models\User;
use App\Models\UserIdentity;
use App\Support\Notifications\NotificationChannels;

/**
 * Единственное место, откуда уходит уведомление.
 *
 * Здесь собраны все условия, при которых портал **не** пишет человеку, — чтобы их не
 * пришлось повторять в каждом событии и чтобы ни одно из них нельзя было случайно
 * обойти, добавив новое событие:
 *
 * - нет галочки — не пишем;
 * - человек не нажал «Старт» у бота — писать некуда, **бот не может написать первым**;
 * - это же уведомление уже уходило — не пишем повторно.
 *
 * Повтор отсекается ключом `dedupe_key`, а не временем: планировщик может сработать
 * дважды, задание из очереди — повториться, а человек не должен получить «Занятия на
 * завтра» дважды за один вечер.
 */
class NotificationDispatcher
{
    public function __construct(private readonly NotificationChannels $channels)
    {
    }

    /**
     * @return NotificationDelivery|null null — отправлять было нечего или некому;
     *                                   решение записано в журнал со статусом `skipped`
     *                                   только в тех случаях, когда его полезно видеть.
     */
    public function send(User $user, string $event, string $dedupeKey, string $text, string $channelCode): ?NotificationDelivery
    {
        $channel = $this->channels->get($channelCode);

        if ($channel === null) {
            return null;
        }

        $subscribed = NotificationSubscription::query()
            ->where('user_id', $user->id)
            ->where('event', $event)
            ->where('channel', $channelCode)
            ->exists();

        if (! $subscribed) {
            return null;
        }

        $chatId = UserIdentity::query()
            ->where('user_id', $user->id)
            ->where('provider', $channelCode)
            ->value('chat_id');

        // Галочка стоит, а диалога нет: человек подписался, но не нажал «Старт».
        // Это записывается — иначе на вопрос «почему не приходит» ответить нечем.
        if (blank($chatId)) {
            return $this->record($user, $event, $channelCode, $dedupeKey, NotificationDelivery::STATUS_SKIPPED, 'Диалог с ботом не начат');
        }

        $delivery = $this->record($user, $event, $channelCode, $dedupeKey, NotificationDelivery::STATUS_FAILED, null);

        // Строка уже была: это же уведомление уходило раньше, второй раз не отправляем.
        if ($delivery === null) {
            return null;
        }

        $sent = $channel->send((string) $chatId, $text);

        $delivery->forceFill([
            'status' => $sent ? NotificationDelivery::STATUS_SENT : NotificationDelivery::STATUS_FAILED,
            'failure_reason' => $sent ? null : 'Канал не принял сообщение',
            'attempts' => $delivery->attempts + 1,
            'sent_at' => $sent ? now() : null,
        ])->save();

        return $delivery;
    }

    /**
     * Запись в журнал и одновременно защита от повтора: уникальность
     * `user_id + channel + dedupe_key` объявлена в схеме, поэтому второй заход строку
     * не создаст. Проверять существование отдельным запросом нельзя — два параллельных
     * задания прошли бы проверку оба.
     *
     * **`insertOrIgnore`, а не `create()` в `try`.** Ловить нарушение уникальности
     * исключением можно только вне транзакции: на PostgreSQL упавший `INSERT`
     * **отравляет всю транзакцию**, и каждый следующий запрос в ней падает с «current
     * transaction is aborted». На SQLite этого не происходит, поэтому такой код
     * проходит весь прогон по умолчанию и краснеет только на PostgreSQL — что и
     * случилось. `insertOrIgnore` разворачивается в `ON CONFLICT DO NOTHING` и
     * транзакцию не трогает.
     */
    private function record(User $user, string $event, string $channel, string $dedupeKey, string $status, ?string $reason): ?NotificationDelivery
    {
        $now = now();

        $inserted = NotificationDelivery::query()->insertOrIgnore([
            'user_id' => $user->id,
            'event' => $event,
            'channel' => $channel,
            'dedupe_key' => $dedupeKey,
            'status' => $status,
            'failure_reason' => $reason,
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 0) {
            return null;
        }

        return NotificationDelivery::query()
            ->where('user_id', $user->id)
            ->where('channel', $channel)
            ->where('dedupe_key', $dedupeKey)
            ->first();
    }
}
