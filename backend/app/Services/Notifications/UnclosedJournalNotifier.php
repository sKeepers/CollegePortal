<?php

namespace App\Services\Notifications;

use App\Models\JournalLesson;
use App\Models\NotificationSubscription;
use App\Models\User;
use App\Support\Notifications\MessageBody;
use App\Support\Notifications\NotificationEvents;
use App\Support\Notifications\RebuildsNotification;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * «Незакрытый журнал» — напоминание преподавателю о вчерашних занятиях без отметок.
 *
 * Утром следующего дня, а не вечером того же: занятие может закончиться поздно, и
 * напоминание через полчаса после звонка человек воспримет как придирку.
 *
 * Незакрытым считается занятие, которое не доведено до «проведено» или «подписано».
 * Отменённое занятие закрывать нечего — оно не состоялось.
 */
class UnclosedJournalNotifier implements RebuildsNotification
{
    private const OPEN_STATUSES = [
        JournalLesson::STATUS_DRAFT,
        JournalLesson::STATUS_PLANNED,
        JournalLesson::STATUS_OPENED,
        JournalLesson::STATUS_IN_PROGRESS,
        JournalLesson::STATUS_REOPENED,
    ];

    public function __construct(private readonly NotificationDispatcher $dispatcher)
    {
    }

    /**
     * @return array{sent: int}
     */
    public function run(CarbonInterface $date, string $channel): array
    {
        $userIds = NotificationSubscription::query()
            ->where('event', NotificationEvents::JOURNAL_UNCLOSED)
            ->where('channel', $channel)
            ->pluck('user_id');

        $sent = 0;

        foreach (User::query()->whereIn('id', $userIds)->with('teacher')->get() as $user) {
            if ($user->teacher === null) {
                continue;
            }

            $lessons = $this->openLessonsFor((int) $user->teacher->id, $date);

            if ($lessons->isEmpty()) {
                continue;
            }

            $delivery = $this->dispatcher->send(
                $user,
                NotificationEvents::JOURNAL_UNCLOSED,
                NotificationEvents::JOURNAL_UNCLOSED.':'.$date->toDateString(),
                $this->compose($lessons, $date),
                $channel,
            );

            if ($delivery?->status === 'sent') {
                $sent++;
            }
        }

        return ['sent' => $sent];
    }

    /** @param Collection<int, JournalLesson> $lessons */
    /**
     * Собрать то же напоминание заново — для повтора неудачной доставки.
     *
     * **Журнал успели закрыть — повторять нечего**, и `null` здесь значит именно это.
     * Напоминание о долге, которого больше нет, читается как придирка и подрывает
     * доверие ко всем остальным.
     */
    public function rebuild(User $user, string $dedupeKey): ?string
    {
        $date = $this->dateFromKey($dedupeKey);

        if ($user->teacher === null || $date === null) {
            return null;
        }

        $lessons = $this->openLessonsFor((int) $user->teacher->id, $date);

        return $lessons->isEmpty() ? null : $this->compose($lessons, $date);
    }

    /**
     * Открытые занятия преподавателя за день.
     *
     * @return Collection<int, JournalLesson>
     */
    private function openLessonsFor(int $teacherId, CarbonInterface $date): Collection
    {
        return JournalLesson::query()
            ->with(['subject', 'group'])
            ->where('teacher_id', $teacherId)
            ->whereDate('lesson_date', $date->toDateString())
            ->whereIn('status', self::OPEN_STATUSES)
            ->orderBy('starts_at')
            ->get();
    }

    /** Дата из ключа повтора: `journal.unclosed:2026-09-01`. */
    private function dateFromKey(string $dedupeKey): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse(Str::after($dedupeKey, ':'))->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function compose(Collection $lessons, CarbonInterface $date): string
    {
        $lines = $lessons->map(function (JournalLesson $lesson): string {
            $time = $lesson->starts_at ? substr((string) $lesson->starts_at, 0, 5) : '';

            return trim(implode(' ', array_filter([
                $time,
                $lesson->subject?->name ?: 'Занятие',
                $lesson->group?->name,
            ])));
        });

        return MessageBody::list("Журнал за {$date->format('d.m')} не закрыт:", $lines);
    }
}
