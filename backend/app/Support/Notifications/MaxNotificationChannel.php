<?php

namespace App\Support\Notifications;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Доставка через бота MAX.
 *
 * Две особенности его Bot API, каждая стоила попытки и обе проверены живьём 11.08.2026:
 *
 * - токен передаётся **только** заголовком `Authorization`; параметр `?access_token=`
 *   объявлен устаревшим и отвечает 401;
 * - в заголовке лежит **голый токен, без `Bearer`** — с приставкой приходит
 *   «Malformed access token», и по виду это неотличимо от неверного токена.
 *
 * Токен в журнал не попадает ни при какой ошибке: в лог пишется код ответа и адресат,
 * но не заголовок и не тело запроса.
 */
final class MaxNotificationChannel implements NotificationChannel
{
    public const CODE = 'max';

    public function __construct(
        private readonly string $botToken,
        private readonly string $baseUrl = 'https://botapi.max.ru',
        private readonly int $timeoutSeconds = 10,
    ) {
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function name(): string
    {
        return 'MAX';
    }

    public function send(string $chatId, string $text): bool
    {
        try {
            $response = Http::withHeaders(['Authorization' => $this->botToken])
                ->timeout($this->timeoutSeconds)
                ->post("{$this->baseUrl}/messages?chat_id={$chatId}", ['text' => $text]);
        } catch (\Throwable $error) {
            // Недоступность мессенджера не должна ронять планировщик: отправку повторит
            // очередь, а здесь достаточно сказать «не доставлено».
            Log::warning('MAX: отправка не удалась', ['chat_id' => $chatId, 'error' => $error->getMessage()]);

            return false;
        }

        if ($response->successful()) {
            return true;
        }

        Log::warning('MAX: отправка отклонена', ['chat_id' => $chatId, 'status' => $response->status()]);

        return false;
    }

    /**
     * Очередь обновлений бота: события `bot_started` и входящие сообщения.
     *
     * Очередь **одна на бота** и читается указателем `marker`, поэтому забирать её должен
     * ровно один процесс — иначе два читателя растащат события друг у друга. Место такому
     * процессу в запланированной задаче, а не в запросе из интерфейса.
     *
     * @return array{updates: list<array<string, mixed>>, marker: int|null}
     */
    public function fetchUpdates(?int $marker = null, int $limit = 100): array
    {
        $query = ['limit' => $limit] + ($marker !== null ? ['marker' => $marker] : []);

        try {
            $response = Http::withHeaders(['Authorization' => $this->botToken])
                ->timeout($this->timeoutSeconds)
                ->get("{$this->baseUrl}/updates", $query);
        } catch (\Throwable $error) {
            Log::warning('MAX: очередь обновлений недоступна', ['error' => $error->getMessage()]);

            return ['updates' => [], 'marker' => $marker];
        }

        if (! $response->successful()) {
            Log::warning('MAX: очередь обновлений отклонена', ['status' => $response->status()]);

            return ['updates' => [], 'marker' => $marker];
        }

        return [
            'updates' => $response->json('updates') ?? [],
            'marker' => $response->json('marker'),
        ];
    }
}
