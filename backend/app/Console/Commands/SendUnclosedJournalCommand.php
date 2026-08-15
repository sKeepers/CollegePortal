<?php

namespace App\Console\Commands;

use App\Services\Notifications\UnclosedJournalNotifier;
use App\Support\Notifications\MaxNotificationChannel;
use Illuminate\Console\Command;

/**
 * Утреннее напоминание преподавателю о вчерашнем незакрытом журнале.
 */
class SendUnclosedJournalCommand extends Command
{
    protected $signature = 'notifications:unclosed-journal {--date= : День занятий, по умолчанию вчера}';

    protected $description = 'Напомнить преподавателям о незакрытом журнале за вчера';

    public function handle(UnclosedJournalNotifier $notifier): int
    {
        $date = $this->option('date') ? now()->parse($this->option('date')) : now()->subDay();

        $result = $notifier->run($date, MaxNotificationChannel::CODE);

        $this->info("Отправлено: {$result['sent']}");

        return self::SUCCESS;
    }
}
