<?php

namespace App\Services\Notifications;

use App\Models\JournalAttendance;
use App\Models\JournalGrade;
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
 * Сводки за день: новые оценки и пропуски.
 *
 * **Сводкой, а не по факту — решение владельца от 11.08.2026,** и оно опирается на замер:
 * 602 студента, 10 218 оценок за две недели, то есть больше оценки в день на человека на
 * обычных учебных неделях. Сообщение на каждую оценку — это не «иногда приходит», это
 * причина отписаться от всего сразу.
 *
 * Оба события живут в одном классе намеренно: они собираются одинаково — взять журнал за
 * день, свернуть в текст, отдать диспетчеру, — и разведённые по двум классам разъехались
 * бы на первой же правке формата.
 */
class DailyJournalDigestNotifier implements RebuildsNotification
{
    public function __construct(private readonly NotificationDispatcher $dispatcher)
    {
    }

    /**
     * @return array{grades: int, attendance: int}
     */
    public function run(CarbonInterface $date, string $channel): array
    {
        return [
            'grades' => $this->send($date, $channel, NotificationEvents::GRADES_DAILY),
            'attendance' => $this->send($date, $channel, NotificationEvents::ATTENDANCE_DAILY),
        ];
    }

    private function send(CarbonInterface $date, string $channel, string $event): int
    {
        $userIds = NotificationSubscription::query()
            ->where('event', $event)
            ->where('channel', $channel)
            ->pluck('user_id');

        $sent = 0;

        foreach (User::query()->whereIn('id', $userIds)->with('student')->get() as $user) {
            $studentId = $user->student?->id;

            if ($studentId === null) {
                continue;
            }

            $text = $event === NotificationEvents::GRADES_DAILY
                ? $this->composeGrades($studentId, $date)
                : $this->composeAttendance($studentId, $date);

            // Пустой день — молчание. Сводка «сегодня ничего не было» каждый вечер
            // обесценивает и те, в которых что-то есть.
            if ($text === null) {
                continue;
            }

            $delivery = $this->dispatcher->send(
                $user,
                $event,
                $event.':'.$date->toDateString(),
                $text,
                $channel,
            );

            if ($delivery?->status === 'sent') {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Собрать ту же сводку заново — для повтора неудачной доставки.
     *
     * Какую из двух, видно по ключу: оба события живут в одном классе, и ключ — то
     * единственное, что их различает после отправки. Пустая сводка возвращает `null`,
     * и это верно: оценку успели снять, отметку исправили — повторять больше нечего.
     */
    public function rebuild(User $user, string $dedupeKey): ?string
    {
        $studentId = $user->student?->id;
        $date = $this->dateFromKey($dedupeKey);

        if ($studentId === null || $date === null) {
            return null;
        }

        return str_starts_with($dedupeKey, NotificationEvents::GRADES_DAILY.':')
            ? $this->composeGrades($studentId, $date)
            : $this->composeAttendance($studentId, $date);
    }

    /** Дата из ключа повтора: `grades.daily:2026-09-01`. */
    private function dateFromKey(string $dedupeKey): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse(Str::after($dedupeKey, ':'))->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function composeGrades(int $studentId, CarbonInterface $date): ?string
    {
        $grades = JournalGrade::query()
            ->with('journalLesson.subject')
            ->where('student_id', $studentId)
            ->whereDate('marked_at', $date->toDateString())
            ->orderBy('marked_at')
            ->get();

        if ($grades->isEmpty()) {
            return null;
        }

        $lines = $grades->map(fn (JournalGrade $grade): string => trim(
            ($grade->journalLesson?->subject?->name ?: 'Дисциплина').': '.$grade->value,
        ));

        return MessageBody::list("Оценки за {$date->format('d.m')}:", $lines);
    }

    private function composeAttendance(int $studentId, CarbonInterface $date): ?string
    {
        // Присутствие — не новость. Сообщать имеет смысл только о том, что человеку
        // придётся объяснять: пропуск, опоздание, отсутствие по болезни.
        $marks = JournalAttendance::query()
            ->with('journalLesson.subject')
            ->where('student_id', $studentId)
            ->whereDate('marked_at', $date->toDateString())
            ->whereIn('status', [
                JournalAttendance::STATUS_ABSENT,
                JournalAttendance::STATUS_LATE,
                JournalAttendance::STATUS_SICK,
                JournalAttendance::STATUS_EXCUSED,
            ])
            ->orderBy('marked_at')
            ->get();

        if ($marks->isEmpty()) {
            return null;
        }

        return MessageBody::list("Посещаемость за {$date->format('d.m')}:", $this->attendanceLines($marks));
    }

    /**
     * @param Collection<int, JournalAttendance> $marks
     * @return Collection<int, string>
     */
    private function attendanceLines(Collection $marks): Collection
    {
        $labels = [
            JournalAttendance::STATUS_ABSENT => 'пропуск',
            JournalAttendance::STATUS_LATE => 'опоздание',
            JournalAttendance::STATUS_SICK => 'болезнь',
            JournalAttendance::STATUS_EXCUSED => 'по уважительной причине',
        ];

        return $marks->map(function (JournalAttendance $mark) use ($labels): string {
            $subject = $mark->journalLesson?->subject?->name ?: 'Занятие';
            $label = $labels[$mark->status] ?? $mark->status;
            $late = $mark->status === JournalAttendance::STATUS_LATE && $mark->minutes_late
                ? " на {$mark->minutes_late} мин"
                : '';

            return "{$subject}: {$label}{$late}";
        });
    }
}
