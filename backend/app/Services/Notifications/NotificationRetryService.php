<?php

namespace App\Services\Notifications;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Support\Notifications\NotificationEvents;
use App\Support\Notifications\RebuildsNotification;

/**
 * Повтор неудачной доставки.
 *
 * **До 24.08.2026 повтора не было вовсе.** `NotificationDispatcher::retryDue()` был
 * написан, снабжён задержками и пределом попыток — и не вызывался ниоткуда, кроме
 * тестов: ни команды, ни строки в планировщике. Первая же неудача теряла уведомление
 * навсегда, `next_attempt_at` проставлялся и не читался никем. Хуже всего, что молча:
 * для планировщика такая доставка уже «обработана», и человек, у которого один раз
 * моргнула сеть, просто не узнавал о переносе занятия.
 *
 * Здесь собрана недостающая половина — кто умеет собрать текст заново. Сам повтор
 * по-прежнему живёт в диспетчере: там задержки, там счёт попыток, там же и отказ от
 * дальнейших попыток.
 *
 * **Повтор обязан уметь сдаться,** иначе он превращается во вторую лавину, только
 * медленную. Сдаётся он тремя разными способами, и каждый нужен:
 *
 * - попыток не больше трёх, задержки 5 минут, полчаса, три часа — заблокированный бот
 *   не разблокируется сам;
 * - доставка старше суток не повторяется вовсе — планировщик мог простоять;
 * - новость умерла — сборка возвращает `null`, и попытки прекращаются: день прошёл,
 *   журнал закрыли, занятие отменили.
 */
class NotificationRetryService
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly LessonsTomorrowNotifier $lessons,
        private readonly DailyJournalDigestNotifier $digest,
        private readonly UnclosedJournalNotifier $journal,
        private readonly ScheduleChangeNotifier $schedule,
    ) {
    }

    /**
     * @return array{retried: int, sent: int, exhausted: int}
     */
    public function run(int $limit = 100): array
    {
        return $this->dispatcher->retryDue(
            fn (NotificationDelivery $delivery): ?string => $this->rebuild($delivery),
            $limit,
        );
    }

    private function rebuild(NotificationDelivery $delivery): ?string
    {
        $builder = $this->builderFor((string) $delivery->event);

        if ($builder === null) {
            return null;
        }

        // Профили нужны каждой сборке: расписание берётся у преподавателя или у группы
        // студента, сводка — у студента. Без них сборка вернула бы пустоту и повтор
        // прекратился бы по неверной причине.
        $user = User::query()->with(['student', 'teacher'])->find($delivery->user_id);

        return $user === null ? null : $builder->rebuild($user, (string) $delivery->dedupe_key);
    }

    private function builderFor(string $event): ?RebuildsNotification
    {
        return match ($event) {
            NotificationEvents::LESSONS_TOMORROW => $this->lessons,
            NotificationEvents::GRADES_DAILY, NotificationEvents::ATTENDANCE_DAILY => $this->digest,
            NotificationEvents::JOURNAL_UNCLOSED => $this->journal,
            NotificationEvents::SCHEDULE_CHANGED => $this->schedule,
            // Событие, у которого нет сборки, повторять нечем. Это не ошибка, а
            // напоминание: добавили событие — добавьте и сюда, иначе его доставки
            // будут прекращаться после первой же неудачи, как было со всеми до 24.08.
            default => null,
        };
    }
}
