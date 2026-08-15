<?php

namespace App\Console\Commands;

use App\Services\Notifications\DailyJournalDigestNotifier;
use App\Support\Notifications\MaxNotificationChannel;
use Illuminate\Console\Command;

/**
 * Вечерние сводки за день: оценки и посещаемость.
 *
 * Повторный запуск безопасен — диспетчер отсекает уже отправленное по ключу «событие
 * и дата», поэтому ручной вызов ничего не продублирует.
 */
class SendJournalDigestCommand extends Command
{
    protected $signature = 'notifications:journal-digest {--date= : День журнала, по умолчанию сегодня}';

    protected $description = 'Разослать подписавшимся сводку оценок и пропусков за день';

    public function handle(DailyJournalDigestNotifier $notifier): int
    {
        $date = $this->option('date') ? now()->parse($this->option('date')) : now();

        $result = $notifier->run($date, MaxNotificationChannel::CODE);

        $this->info("Оценки: {$result['grades']}, посещаемость: {$result['attendance']}");

        return self::SUCCESS;
    }
}
