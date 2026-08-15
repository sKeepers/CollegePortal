<?php

namespace App\Console\Commands;

use App\Services\Notifications\ScheduleChangeNotifier;
use App\Support\Notifications\MaxNotificationChannel;
use Illuminate\Console\Command;

/**
 * Рассылка изменений расписания за последнее окно.
 *
 * Окно и период запуска обязаны совпадать: разойдутся — правки либо продублируются,
 * либо провалятся между запусками.
 */
class SendScheduleChangesCommand extends Command
{
    public const WINDOW_MINUTES = 15;

    protected $signature = 'notifications:schedule-changes {--minutes= : Ширина окна в минутах}';

    protected $description = 'Разослать подписавшимся изменения расписания за последнее окно';

    public function handle(ScheduleChangeNotifier $notifier): int
    {
        $minutes = (int) ($this->option('minutes') ?: self::WINDOW_MINUTES);
        $since = now()->subMinutes($minutes)->startOfMinute();

        $result = $notifier->run($since, MaxNotificationChannel::CODE);

        $this->info("Изменено занятий: {$result['changed']}, отправлено: {$result['sent']}");

        return self::SUCCESS;
    }
}
