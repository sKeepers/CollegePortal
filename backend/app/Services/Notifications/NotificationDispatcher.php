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
    /** Больше трёх попыток бессмысленно: заблокированный бот не разблокируется сам. */
    public const MAX_ATTEMPTS = 3;

    /** Пять минут, полчаса, три часа — задержка перед второй, третьей и далее попыткой. */
    private const RETRY_DELAYS_MINUTES = [5, 30, 180];

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

        return $this->attempt($delivery, $channel, (string) $chatId, $text);
    }

    /**
     * Повторить отправку тех, у кого срок следующей попытки настал.
     *
     * Мессенджер отвечает отказом чаще, чем кажется: человек удалил бота, заблокировал,
     * сменил аккаунт, сеть моргнула. Без повтора первая же неудача теряет уведомление
     * навсегда — и незаметно, потому что для планировщика она уже «обработана».
     *
     * Текста в журнале нет намеренно, поэтому повторяется **не текст, а факт**: сообщение
     * собирается заново тем же событием. Значит повторять умеет только тот, кто умеет
     * его составить, и здесь повтор ограничен доставкой — сборку передаёт вызывающий.
     *
     * @param callable(NotificationDelivery): ?string $compose как собрать текст заново;
     *        `null` — событие больше неактуально, повторять нечего
     * @return array{retried: int, sent: int, exhausted: int}
     */
    public function retryDue(callable $compose, int $limit = 100): array
    {
        $due = NotificationDelivery::query()
            ->where('status', NotificationDelivery::STATUS_FAILED)
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')
            ->limit($limit)
            ->get();

        $result = ['retried' => 0, 'sent' => 0, 'exhausted' => 0];

        foreach ($due as $delivery) {
            $channel = $this->channels->get($delivery->channel);
            $chatId = UserIdentity::query()
                ->where('user_id', $delivery->user_id)
                ->where('provider', $delivery->channel)
                ->value('chat_id');
            $text = $compose($delivery);

            // Событие устарело или писать снова некуда — попытки прекращаем, но строку
            // оставляем: по ней видно, что уведомление не дошло и почему.
            if ($channel === null || blank($chatId) || $text === null) {
                $delivery->forceFill(['next_attempt_at' => null])->save();
                $result['exhausted']++;

                continue;
            }

            $result['retried']++;
            $this->attempt($delivery, $channel, (string) $chatId, $text);

            if ($delivery->status === NotificationDelivery::STATUS_SENT) {
                $result['sent']++;
            } elseif ($delivery->attempts >= self::MAX_ATTEMPTS) {
                $result['exhausted']++;
            }
        }

        return $result;
    }

    /**
     * Одна попытка доставки и её след в журнале.
     *
     * Задержка растёт: пять минут, полчаса, три часа. Повторять чаще бессмысленно —
     * если человек заблокировал бота, ничего не изменится ни через минуту, ни через час,
     * а частые попытки только упрутся в ограничения мессенджера.
     */
    private function attempt(NotificationDelivery $delivery, $channel, string $chatId, string $text): NotificationDelivery
    {
        $sent = $channel->send($chatId, $text);
        $attempts = $delivery->attempts + 1;

        $delivery->forceFill([
            'status' => $sent ? NotificationDelivery::STATUS_SENT : NotificationDelivery::STATUS_FAILED,
            'failure_reason' => $sent ? null : 'Канал не принял сообщение',
            'attempts' => $attempts,
            'sent_at' => $sent ? now() : null,
            'next_attempt_at' => $sent || $attempts >= self::MAX_ATTEMPTS
                ? null
                : now()->addMinutes(self::RETRY_DELAYS_MINUTES[$attempts - 1] ?? 180),
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
