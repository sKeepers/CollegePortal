<?php

namespace App\Console\Commands;

use App\Services\Notifications\LessonsTomorrowNotifier;
use App\Support\Notifications\MaxNotificationChannel;
use Illuminate\Console\Command;

/**
 * Вечерняя рассылка «Занятия на завтра».
 *
 * Запускается планировщиком раз в день. Повторный запуск безопасен: диспетчер отсекает
 * уже отправленное по ключу «событие и дата», поэтому ручной вызов ничего не продублирует.
 */
class SendLessonsTomorrowCommand extends Command
{
    protected $signature = 'notifications:lessons-tomorrow {--date= : Дата занятий, по умолчанию завтра}';

    protected $description = 'Разослать подписавшимся расписание на завтра';

    public function handle(LessonsTomorrowNotifier $notifier): int
    {
        $date = $this->option('date') ? now()->parse($this->option('date')) : now()->addDay();

        $result = $notifier->run($date, MaxNotificationChannel::CODE);

        $this->info("Подписано: {$result['considered']}, отправлено: {$result['sent']}");

        return self::SUCCESS;
    }
}
