<?php

namespace App\Services\Notifications;

use App\Models\NotificationSubscription;
use App\Models\ScheduleLesson;
use App\Models\User;
use App\Support\Notifications\MessageBody;
use App\Support\Notifications\NotificationEvents;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * «Изменения в расписании».
 *
 * **Отправляется не наблюдателем, а окном.** Наблюдатель на модели выглядит естественнее,
 * но у него нет защиты от бури: загрузка расписания на семестр создаёт больше полутора
 * тысяч занятий разом, и каждое стало бы сообщением. Здесь вместо этого берётся окно
 * времени, изменения сворачиваются в одно сообщение на человека, и буря превращается
 * в строку «изменилось занятий: N».
 *
 * **Появление нового занятия изменением не считается.** Опубликованное расписание на
 * семестр — это не «расписание изменилось», это расписание появилось; иначе первая же
 * загрузка разослала бы уведомление всему колледжу. Признак правки — `updated_at`
 * заметно позже `created_at`.
 *
 * **Смотрим только вперёд.** Изменение вчерашнего занятия человеку уже не пригодится:
 * он либо был на нём, либо нет.
 */
class ScheduleChangeNotifier
{
    /** Насколько `updated_at` должен обогнать `created_at`, чтобы считаться правкой. */
    private const EDIT_THRESHOLD_SECONDS = 60;

    public function __construct(private readonly NotificationDispatcher $dispatcher)
    {
    }

    /**
     * @param CarbonInterface $since начало окна: за какой период собирать правки
     * @return array{changed: int, sent: int}
     */
    public function run(CarbonInterface $since, string $channel): array
    {
        $lessons = ScheduleLesson::query()
            ->with(['subject', 'classroom'])
            ->where('updated_at', '>=', $since)
            ->whereDate('lesson_date', '>=', now()->toDateString())
            ->whereColumn('updated_at', '>', 'created_at')
            ->get()
            // Разница считается по меткам времени, а не через `diffInSeconds`: в Carbon 3
            // он знаковый, и «позже на 30 дней» возвращается отрицательным числом.
            // Проверка молча пропускала бы всё.
            ->filter(fn (ScheduleLesson $lesson): bool => $lesson->created_at !== null
                && $lesson->updated_at->getTimestamp() - $lesson->created_at->getTimestamp() >= self::EDIT_THRESHOLD_SECONDS);

        if ($lessons->isEmpty()) {
            return ['changed' => 0, 'sent' => 0];
        }

        $userIds = NotificationSubscription::query()
            ->where('event', NotificationEvents::SCHEDULE_CHANGED)
            ->where('channel', $channel)
            ->pluck('user_id');

        $sent = 0;

        foreach (User::query()->whereIn('id', $userIds)->with(['student', 'teacher'])->get() as $user) {
            $mine = $this->forUser($lessons, $user);

            if ($mine->isEmpty()) {
                continue;
            }

            $delivery = $this->dispatcher->send(
                $user,
                NotificationEvents::SCHEDULE_CHANGED,
                // Ключ включает окно: расписание могут поправить дважды за день, и это
                // две разные новости, а не повтор одной.
                NotificationEvents::SCHEDULE_CHANGED.':'.$since->format('Y-m-d-H-i'),
                $this->compose($mine),
                $channel,
            );

            if ($delivery?->status === 'sent') {
                $sent++;
            }
        }

        return ['changed' => $lessons->count(), 'sent' => $sent];
    }

    /**
     * @param Collection<int, ScheduleLesson> $lessons
     * @return Collection<int, ScheduleLesson>
     */
    private function forUser(Collection $lessons, User $user): Collection
    {
        if ($user->teacher) {
            return $lessons->where('teacher_id', $user->teacher->id);
        }

        if ($user->student?->group_id) {
            return $lessons->where('group_id', $user->student->group_id);
        }

        return collect();
    }

    /** @param Collection<int, ScheduleLesson> $lessons */
    private function compose(Collection $lessons): string
    {
        $lines = $lessons
            ->sortBy(['lesson_date', 'starts_at'])
            ->map(function (ScheduleLesson $lesson): string {
                $date = $lesson->lesson_date?->format('d.m') ?: '';
                $time = $lesson->starts_at ? substr((string) $lesson->starts_at, 0, 5) : '';
                $room = $lesson->classroom?->number;

                return trim(implode(' ', array_filter([
                    $date,
                    $time,
                    $lesson->subject?->name ?: 'Занятие',
                    $room ? "ауд. {$room}" : null,
                ])));
            });

        // Заголовок называет число сразу: при подрезанном списке человек должен
        // видеть, сколько занятий тронули, а не только первые десять.
        return MessageBody::list('Расписание изменилось, занятий: '.$lessons->count(), $lines);
    }
}
