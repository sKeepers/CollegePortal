<?php

namespace App\Services\Notifications;

use App\Models\NotificationSubscription;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\AuditLogService;
use App\Support\Notifications\NotificationChannels;
use App\Support\Notifications\NotificationEvents;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Галочки уведомлений: что человек согласился получать и куда.
 *
 * Согласие явное и отзываемое — это требование, а не удобство. По умолчанию не подписан
 * никто, снятая галочка прекращает отправку немедленно, и обе стороны пишутся в журнал
 * аудита: содержимое уведомлений неанонимно, поэтому момент согласия должен быть виден.
 */
class NotificationSubscriptionService
{
    public function __construct(private readonly NotificationChannels $channels)
    {
    }

    /**
     * Состояние раздела: каналы, готовность диалога и галочки с отметкой «включено».
     *
     * @return array<string, mixed>
     */
    public function overview(User $user): array
    {
        $subscribed = NotificationSubscription::query()
            ->where('user_id', $user->id)
            ->get()
            ->map(fn (NotificationSubscription $row): string => $row->event.'|'.$row->channel)
            ->all();

        $channels = array_map(function (array $channel) use ($user): array {
            $identity = UserIdentity::query()
                ->where('user_id', $user->id)
                ->where('provider', $channel['code'])
                ->first();

            return $channel + [
                // Без диалога галочка — обещание, которое портал не выполнит, поэтому
                // экран обязан показывать это отдельно от самой подписки.
                'chat_ready' => filled($identity?->chat_id),
                'linked' => $identity !== null,
            ];
        }, $this->channels->available());

        // Кто получает уведомления о человеке, кроме него самого. Отключить их он не
        // может — так решил владелец, — но узнавать о них случайно не должен: скрытая
        // рассылка о себе обнаруживается в худший момент.
        $watchers = NotificationSubscription::query()
            ->where('subject_user_id', $user->id)
            ->where('user_id', '!=', $user->id)
            ->with('user')
            ->get()
            ->map(fn (NotificationSubscription $row): array => [
                'name' => $row->user?->name,
                'event' => $row->event,
            ])
            ->values();

        return [
            'watchers' => $watchers,
            'channels' => $channels,
            'events' => array_map(static fn (array $event): array => $event + [
                'enabled' => array_map(
                    static fn (array $channel): bool => in_array($event['code'].'|'.$channel['code'], $subscribed, true),
                    $channels,
                ),
            ], NotificationEvents::all()),
            'subscribed' => $subscribed,
        ];
    }

    public function set(User $user, string $event, string $channelCode, bool $enabled, Request $request): void
    {
        if (! NotificationEvents::exists($event)) {
            throw ValidationException::withMessages(['event' => 'Такого события нет.']);
        }

        if ($this->channels->get($channelCode) === null) {
            throw ValidationException::withMessages(['channel' => 'Такой способ доставки не подключён.']);
        }

        if ($enabled) {
            // `firstOrNew` + `save`, а не `firstOrCreate`: последний внутри транзакции
            // открывает точку сохранения на каждую вставку, а таблица блокировок одна
            // на сервер — на этом уже валился демонстрационный набор, см. «Грабли».
            $subscription = NotificationSubscription::firstOrNew([
                'user_id' => $user->id,
                'event' => $event,
                'channel' => $channelCode,
            ]);

            if (! $subscription->exists) {
                // Собственная подписка: получатель и предмет совпадают. Расходятся они
                // там, где о человеке пишут кому-то ещё, — это заводится не отсюда.
                $subscription->subject_user_id = $user->id;
                $subscription->save();
            }
        } else {
            NotificationSubscription::query()
                ->where('user_id', $user->id)
                ->where('event', $event)
                ->where('channel', $channelCode)
                ->delete();
        }

        AuditLogService::log('auth', $enabled ? 'notification_subscribed' : 'notification_unsubscribed', [
            'type' => 'NotificationSubscription',
            'id' => null,
        ], null, ['event' => $event, 'channel' => $channelCode], $request, $user);
    }
}
