<?php

namespace App\Console\Commands;

use App\Services\Notifications\NotificationRetryService;
use Illuminate\Console\Command;

/**
 * Повторить доставки, у которых настал срок следующей попытки.
 *
 * Запускается чаще всех остальных рассылок, потому что первая задержка — пять минут:
 * реже, и обещанный повтор превращается в отложенный на полчаса.
 */
class SendNotificationRetriesCommand extends Command
{
    protected $signature = 'notifications:retry {--limit=100 : Сколько доставок брать за раз}';

    protected $description = 'Повторить неудачные доставки уведомлений';

    public function handle(NotificationRetryService $service): int
    {
        $result = $service->run((int) $this->option('limit'));

        $this->info("Повторено: {$result['retried']}, доставлено: {$result['sent']}, прекращено: {$result['exhausted']}");

        return self::SUCCESS;
    }
}
