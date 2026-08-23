<?php

namespace App\Services\Notifications;

use App\Models\JournalLesson;
use App\Models\NotificationSubscription;
use App\Models\User;
use App\Support\Notifications\MessageBody;
use App\Support\Notifications\NotificationEvents;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * «Незакрытый журнал» — напоминание преподавателю о вчерашних занятиях без отметок.
 *
 * Утром следующего дня, а не вечером того же: занятие может закончиться поздно, и
 * напоминание через полчаса после звонка человек воспримет как придирку.
 *
 * Незакрытым считается занятие, которое не доведено до «проведено» или «подписано».
 * Отменённое занятие закрывать нечего — оно не состоялось.
 */
class UnclosedJournalNotifier
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

            $lessons = JournalLesson::query()
                ->with(['subject', 'group'])
                ->where('teacher_id', $user->teacher->id)
                ->whereDate('lesson_date', $date->toDateString())
                ->whereIn('status', self::OPEN_STATUSES)
                ->orderBy('starts_at')
                ->get();

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
