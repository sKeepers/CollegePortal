<?php

namespace App\Services\Notifications;

use App\Models\NotificationDelivery;
use App\Models\NotificationSubscription;
use App\Models\ScheduleLesson;
use App\Models\User;
use App\Support\Notifications\MessageBody;
use App\Support\Notifications\NotificationEvents;
use App\Support\Notifications\RebuildsNotification;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
 *
 * **И не чаще раза в час одному человеку.** Свёртка окном ограничивает сообщение, но не
 * их число: окон в сутках девяносто шесть, и пока учебная часть правит расписание, в
 * каждом окне уходит по сообщению. Замер на стенде 24.08.2026: восемь окон подряд при
 * непрерывной правке — **4960 сообщений на 620 человек, ровно по восемь каждому**; за
 * рабочий день первого сентября это тридцать два. Ровно от такого отписываются разом и
 * навсегда. Поэтому у каждого человека есть время тишины: пока оно не вышло, правки
 * копятся, а следующее сообщение собирается **с момента прошлого**, а не с начала окна, —
 * так число сообщений падает, и ни одна правка при этом не теряется.
 */
class ScheduleChangeNotifier implements RebuildsNotification
{
    /** Насколько `updated_at` должен обогнать `created_at`, чтобы считаться правкой. */
    private const EDIT_THRESHOLD_SECONDS = 60;

    /** Не чаще одного сообщения об изменениях расписания в час на человека. */
    public const COOLDOWN_MINUTES = 60;

    public function __construct(private readonly NotificationDispatcher $dispatcher)
    {
    }

    /**
     * @param CarbonInterface $since начало окна: за какой период собирать правки
     * @param int|null $cooldownMinutes время тишины для одного человека; `0` — без него
     * @return array{changed: int, sent: int, held: int}
     */
    public function run(CarbonInterface $since, string $channel, ?int $cooldownMinutes = null): array
    {
        $cooldown = $cooldownMinutes ?? self::COOLDOWN_MINUTES;

        // Заглянуть придётся дальше окна: человеку, который час молчал, сообщение
        // собирается с его прошлого сообщения, а оно старше начала окна.
        $lookback = $since->copy()->subMinutes($cooldown);
        $lessons = $this->changedSince($lookback);

        if ($lessons->isEmpty()) {
            return ['changed' => 0, 'sent' => 0, 'held' => 0];
        }

        $userIds = NotificationSubscription::query()
            ->where('event', NotificationEvents::SCHEDULE_CHANGED)
            ->where('channel', $channel)
            ->pluck('user_id');

        $users = User::query()->whereIn('id', $userIds)->with(['student', 'teacher'])->get();
        $lastSent = $this->lastSentAt($users->pluck('id')->all(), $channel);
        $now = now();
        $sent = 0;
        $held = 0;

        foreach ($users as $user) {
            $last = $lastSent[$user->id] ?? null;

            // Время тишины ещё идёт: правки этого человека подождут следующего окна и
            // уйдут одним сообщением вместе с теми, что случатся до него.
            if ($last !== null && $now->getTimestamp() - $last->getTimestamp() < $cooldown * 60) {
                $held++;

                continue;
            }

            // Начало счёта — прошлое сообщение, а не начало окна: иначе всё, что
            // накопилось за время тишины, потерялось бы молча.
            $from = $last !== null && $last->getTimestamp() > $lookback->getTimestamp() ? $last : $since;

            $mine = $this->forUser($lessons, $user)
                ->filter(fn (ScheduleLesson $lesson): bool => $lesson->updated_at->getTimestamp() > $from->getTimestamp());

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

        return ['changed' => $lessons->count(), 'sent' => $sent, 'held' => $held];
    }

    /**
     * Собрать то же сообщение заново — для повтора неудачной доставки.
     *
     * Окно берётся из ключа: он и есть та подробность, которая отличает одну новость об
     * изменениях от другой. Правки, случившиеся после того окна, сюда не попадают
     * намеренно — их принесёт своё сообщение, а не чужой повтор.
     */
    public function rebuild(User $user, string $dedupeKey): ?string
    {
        $since = $this->windowFromKey($dedupeKey);

        if ($since === null) {
            return null;
        }

        $mine = $this->forUser($this->changedSince($since), $user);

        // Занятие успели отменить, а вместе с ним исчезла и строка расписания: новость
        // умерла, повторять нечего.
        return $mine->isEmpty() ? null : $this->compose($mine);
    }

    /**
     * Правки будущих занятий начиная с указанного момента.
     *
     * @return Collection<int, ScheduleLesson>
     */
    private function changedSince(CarbonInterface $from): Collection
    {
        return ScheduleLesson::query()
            ->with(['subject', 'classroom'])
            ->where('updated_at', '>=', $from)
            ->whereDate('lesson_date', '>=', now()->toDateString())
            ->whereColumn('updated_at', '>', 'created_at')
            ->get()
            // Разница считается по меткам времени, а не через `diffInSeconds`: в Carbon 3
            // он знаковый, и «позже на 30 дней» возвращается отрицательным числом.
            // Проверка молча пропускала бы всё.
            ->filter(fn (ScheduleLesson $lesson): bool => $lesson->created_at !== null
                && $lesson->updated_at->getTimestamp() - $lesson->created_at->getTimestamp() >= self::EDIT_THRESHOLD_SECONDS);
    }

    /** Начало окна из ключа повтора: `schedule.changed:2026-09-01-08-15`. */
    private function windowFromKey(string $dedupeKey): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('Y-m-d-H-i', Str::after($dedupeKey, ':')) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Когда каждому из них писали об изменениях в последний раз.
     *
     * Считается по журналу доставок, а не по отдельной колонке: журнал уже есть, он
     * переживает перезапуск и в нём видно решение — в том числе `skipped`, когда диалога
     * с ботом нет. Молчание такому человеку тоже стоит держать: строк в журнале за день
     * иначе набегает столько же, сколько было бы сообщений.
     *
     * @param array<int, int> $userIds
     * @return array<int, CarbonInterface>
     */
    private function lastSentAt(array $userIds, string $channel): array
    {
        if ($userIds === []) {
            return [];
        }

        return NotificationDelivery::query()
            ->whereIn('user_id', $userIds)
            ->where('event', NotificationEvents::SCHEDULE_CHANGED)
            ->where('channel', $channel)
            ->selectRaw('user_id, max(created_at) as last_at')
            ->groupBy('user_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->user_id => now()->parse($row->last_at)])
            ->all();
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
