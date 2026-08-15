<?php

namespace App\Console\Commands;

use App\Services\Notifications\MaxLinkService;
use App\Support\Notifications\NotificationChannels;
use App\Support\Notifications\MaxNotificationChannel;
use Illuminate\Console\Command;

/**
 * Вычитывание очереди обновлений бота MAX: кто прислал код привязки.
 *
 * Очередь у бота одна и читается по указателю, поэтому запускать это должен **один**
 * процесс — планировщик. Два одновременно растащат события друг у друга, и часть
 * привязок молча потеряется.
 */
class PullMaxUpdatesCommand extends Command
{
    protected $signature = 'notifications:max-pull';

    protected $description = 'Забрать обновления бота MAX и связать присланные коды привязки';

    public function handle(NotificationChannels $channels, MaxLinkService $links): int
    {
        // Без токена в `.env` канала нет вовсе — молча выходим, а не падаем каждую
        // минуту в журнале планировщика.
        if ($channels->get(MaxNotificationChannel::CODE) === null) {
            return self::SUCCESS;
        }

        $linked = $links->pullUpdates();

        if ($linked > 0) {
            $this->info("Привязок сделано: {$linked}");
        }

        return self::SUCCESS;
    }
}
