<?php

namespace App\Services\Notifications;

use App\Models\NotificationSubscription;
use App\Models\ScheduleLesson;
use App\Models\User;
use App\Support\Notifications\NotificationEvents;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * «Занятия на завтра» — первое событие уведомлений.
 *
 * Выбрано первым осознанно: оно ежедневное, предсказуемое, проверяется глазами и не
 * упирается ни в один открытый вопрос — в отличие от оценок, где нужен был ответ про
 * сводку, и задолженностей, где в портале нет дат периода.
 *
 * Одно сообщение на человека в день, а не по сообщению на занятие: расписание — это
 * список, и присылать его строками значит гарантированно получить отписку.
 */
class LessonsTomorrowNotifier
{
    public function __construct(private readonly NotificationDispatcher $dispatcher)
    {
    }

    /**
     * @return array{considered: int, sent: int}
     */
    public function run(CarbonInterface $date, string $channel): array
    {
        $userIds = NotificationSubscription::query()
            ->where('event', NotificationEvents::LESSONS_TOMORROW)
            ->where('channel', $channel)
            ->pluck('user_id');

        $users = User::query()->whereIn('id', $userIds)->with(['student', 'teacher'])->get();
        $sent = 0;

        foreach ($users as $user) {
            $lessons = $this->lessonsFor($user, $date);

            // Пустой день — не новость. Сообщение «завтра занятий нет» человек прочитает
            // один раз, а получать его каждое воскресенье не захочет.
            if ($lessons->isEmpty()) {
                continue;
            }

            $delivery = $this->dispatcher->send(
                $user,
                NotificationEvents::LESSONS_TOMORROW,
                // Ключ повтора — событие и дата: планировщик может сработать дважды,
                // человек не должен получить расписание дважды за вечер.
                NotificationEvents::LESSONS_TOMORROW.':'.$date->toDateString(),
                $this->compose($lessons, $date),
                $channel,
            );

            if ($delivery?->status === 'sent') {
                $sent++;
            }
        }

        return ['considered' => $users->count(), 'sent' => $sent];
    }

    /** @return Collection<int, ScheduleLesson> */
    private function lessonsFor(User $user, CarbonInterface $date): Collection
    {
        $query = ScheduleLesson::query()
            ->with(['subject', 'classroom'])
            ->whereDate('lesson_date', $date->toDateString())
            ->orderBy('starts_at');

        // Кому что показывать, решает профиль, а не роль: у преподавателя своё
        // расписание, у студента — расписание его группы.
        if ($user->teacher) {
            return $query->where('teacher_id', $user->teacher->id)->get();
        }

        if ($user->student?->group_id) {
            return $query->where('group_id', $user->student->group_id)->get();
        }

        return collect();
    }

    /** @param Collection<int, ScheduleLesson> $lessons */
    private function compose(Collection $lessons, CarbonInterface $date): string
    {
        $lines = $lessons->map(function (ScheduleLesson $lesson): string {
            $time = $lesson->starts_at ? substr((string) $lesson->starts_at, 0, 5) : '';
            // У аудитории номер, а не название: `name` здесь вернул бы пустоту молча.
            $room = $lesson->classroom?->number;

            return trim(implode(' ', array_filter([
                $time,
                $lesson->subject?->name ?: 'Занятие',
                $room ? "ауд. {$room}" : null,
            ])));
        });

        return "Занятия на {$date->format('d.m')}:\n".$lines->implode("\n");
    }
}
