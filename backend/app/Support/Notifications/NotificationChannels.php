<?php

namespace App\Support\Notifications;

/**
 * Список подключённых каналов доставки.
 *
 * Пустой список — состояние, а не оплошность: без токена бота в `.env` уведомлений нет,
 * галочки в кабинете не показываются, и портал работает как до `NOTIFY-001`.
 */
final class NotificationChannels
{
    /** @var array<string, NotificationChannel> */
    private array $channels = [];

    /** @param iterable<NotificationChannel> $channels */
    public function __construct(iterable $channels = [])
    {
        foreach ($channels as $channel) {
            $this->channels[$channel->code()] = $channel;
        }
    }

    public function get(string $code): ?NotificationChannel
    {
        return $this->channels[$code] ?? null;
    }

    /** @return list<array{code: string, name: string}> */
    public function available(): array
    {
        return array_values(array_map(
            static fn (NotificationChannel $channel): array => ['code' => $channel->code(), 'name' => $channel->name()],
            $this->channels,
        ));
    }
}
