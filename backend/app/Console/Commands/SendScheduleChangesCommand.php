<?php

namespace App\Console\Commands;

use App\Services\Notifications\ScheduleChangeNotifier;
use App\Support\Notifications\MaxNotificationChannel;
use Illuminate\Console\Command;

/**
 * Окно рассылки изменений расписания.
 *
 * Ширина окна и период запуска в `routes/console.php` обязаны совпадать: разойдутся —
 * правки либо продублируются, либо провалятся между запусками.
 *
 * `--cooldown` меняет время тишины на человека (по умолчанию час). Ноль отключает его
 * совсем — это годится для замера и не годится для боя: без тишины каждое окно шлёт
 * сообщение каждому, у кого что-то тронули, и рабочий день правок превращается в
 * тридцать два сообщения.
 */
class SendScheduleChangesCommand extends Command
{
    public const WINDOW_MINUTES = 15;

    protected $signature = 'notifications:schedule-changes
        {--minutes= : Ширина окна в минутах}
        {--cooldown= : Время тишины на человека в минутах, 0 — без него}';

    protected $description = 'Разослать подписавшимся изменения расписания за последнее окно';

    public function handle(ScheduleChangeNotifier $notifier): int
    {
        $minutes = (int) ($this->option('minutes') ?: self::WINDOW_MINUTES);
        $cooldown = $this->option('cooldown') === null ? null : (int) $this->option('cooldown');
        $since = now()->subMinutes($minutes)->startOfMinute();
        $result = $notifier->run($since, MaxNotificationChannel::CODE, $cooldown);

        $this->info("Изменено занятий: {$result['changed']}, отправлено: {$result['sent']}, придержано до следующего часа: {$result['held']}");

        return self::SUCCESS;
    }
}
