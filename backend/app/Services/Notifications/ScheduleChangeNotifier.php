<?php

namespace App\Services\Notifications;

use App\Models\NotificationDelivery;
use App\Models\NotificationSubscription;
use App\Models\ScheduleEntry;
use App\Models\ScheduleLesson;
use App\Models\User;
use App\Support\Notifications\MessageBody;
use App\Support\Notifications\NotificationEvents;
use App\Support\Notifications\RebuildsNotification;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Database\Eloquent\Collection;
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
 * **Отмена ищется отдельно и идёт первой строкой.** Отменённое занятие исчезает из
 * `schedule_lessons` совсем — `ScheduleEngineService::cancel()` удаляет зеркальную строку,
 * мягкого удаления у модели нет, — и рассылка, которая смотрит только туда, отмену найти
 * не могла в принципе. Получалось наоборот тому, что нужно: о переносе аудитории человек
 * узнавал, а о том, что занятия не будет, — нет, и приходил в колледж зря. Поэтому отмены
 * берутся из `schedule_entries` и ставятся **в начало списка**: список подрезается десятью
 * строками, и вытеснить отмену переносом было бы худшим из возможных порядков.
 *
 * **Возврат ищется третьим способом.** Отменённое занятие могут вернуть, и тогда строка
 * расписания заводится заново — новая при старой записи движка. Правкой это не выглядит
 * (`updated_at` равен `created_at`), отменой больше не является, и без отдельного поиска
 * получалось половинчато: человеку сказали «отменено» и не сказали, что занятие вернулось.
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
        $cancelled = $this->cancelledSince($lookback);
        $restored = $this->restoredSince($lookback);

        if ($lessons->isEmpty() && $cancelled->isEmpty() && $restored->isEmpty()) {
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

            $mine = $this->sinceMoment($this->forUser($lessons, $user), $from);
            $mineCancelled = $this->sinceMoment($this->forUser($cancelled, $user), $from);
            $mineRestored = $this->sinceMoment($this->forUser($restored, $user), $from);

            if ($mine->isEmpty() && $mineCancelled->isEmpty() && $mineRestored->isEmpty()) {
                continue;
            }

            $delivery = $this->dispatcher->send(
                $user,
                NotificationEvents::SCHEDULE_CHANGED,
                // Ключ включает окно: расписание могут поправить дважды за день, и это
                // две разные новости, а не повтор одной.
                NotificationEvents::SCHEDULE_CHANGED.':'.$since->format('Y-m-d-H-i'),
                $this->compose($mine, $mineCancelled, $mineRestored),
                $channel,
            );

            if ($delivery?->status === 'sent') {
                $sent++;
            }
        }

        return ['changed' => $lessons->count() + $cancelled->count() + $restored->count(), 'sent' => $sent, 'held' => $held];
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
        $mineCancelled = $this->forUser($this->cancelledSince($since), $user);
        $mineRestored = $this->forUser($this->restoredSince($since), $user);

        // Ни правок, ни отмен, ни возвратов: новость умерла, повторять нечего.
        return $mine->isEmpty() && $mineCancelled->isEmpty() && $mineRestored->isEmpty()
            ? null
            : $this->compose($mine, $mineCancelled, $mineRestored);
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

    /**
     * Отменённые будущие занятия начиная с указанного момента.
     *
     * Берутся из `schedule_entries`, а не из `schedule_lessons`: отмена **удаляет**
     * зеркальную строку, и в расписании занятия больше нет вовсе.
     *
     * Тот же порог, что и у правок: занятие, заведённое и отменённое в одну минуту,
     * новостью не является — о нём никто не успел узнать.
     *
     * @return Collection<int, ScheduleEntry>
     */
    private function cancelledSince(CarbonInterface $from): Collection
    {
        return ScheduleEntry::query()
            ->with(['subject', 'classroom'])
            ->where('status', 'canceled')
            ->where('updated_at', '>=', $from)
            ->whereNotNull('date')
            ->whereDate('date', '>=', now()->toDateString())
            ->get()
            ->filter(fn (ScheduleEntry $entry): bool => $entry->created_at !== null
                && $entry->updated_at->getTimestamp() - $entry->created_at->getTimestamp() >= self::EDIT_THRESHOLD_SECONDS);
    }

    /**
     * Отсечь то, о чём человеку уже говорили.
     *
     * @template T of ScheduleLesson|ScheduleEntry
     * @param Collection<int, T> $items
     * @return Collection<int, T>
     */
    private function sinceMoment(Collection $items, CarbonInterface $from): Collection
    {
        return $items->filter(fn ($item): bool => $item->updated_at->getTimestamp() > $from->getTimestamp());
    }

    /**
     * Занятия, вернувшиеся в расписание.
     *
     * Отмена **удаляет** зеркальную строку, возврат заводит её заново. Поэтому у
     * вернувшегося занятия строка расписания новая, а запись движка старая, — и этим
     * возврат отличается от загрузки семестра, где новы обе. Там между ними микросекунды,
     * здесь — всё время, что занятие простояло отменённым; порог в минуту разводит эти
     * два случая надёжно.
     *
     * Без этого выходило половинчато: человеку сказали «отменено» и не сказали, что
     * занятие вернулось, — он не пришёл на то, которое состоялось.
     *
     * @return Collection<int, ScheduleLesson>
     */
    private function restoredSince(CarbonInterface $from): Collection
    {
        return ScheduleLesson::query()
            ->with(['subject', 'classroom', 'scheduleEntry'])
            ->where('created_at', '>=', $from)
            ->whereDate('lesson_date', '>=', now()->toDateString())
            ->whereHas('scheduleEntry', fn (Builder $query) => $query->where('status', 'scheduled'))
            ->get()
            ->filter(fn (ScheduleLesson $lesson): bool => $lesson->scheduleEntry?->created_at !== null
                && $lesson->created_at->getTimestamp() - $lesson->scheduleEntry->created_at->getTimestamp() >= self::EDIT_THRESHOLD_SECONDS);
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

    /**
     * Тело сообщения: сначала отмены, затем вернувшиеся, затем правки.
     *
     * Порядок не косметика. Список подрезается десятью строками, и если отмену вытеснит
     * перенос аудитории, человек прочитает про аудиторию и придёт на занятие, которого
     * нет. Отмена — самая дорогая новость из всех, ей и первое место; возврат следом,
     * потому что его цена такая же, только с обратным знаком.
     *
     * @param Collection<int, ScheduleLesson> $changed
     * @param Collection<int, ScheduleEntry> $cancelled
     * @param Collection<int, ScheduleLesson> $restored
     */
    private function compose(Collection $changed, Collection $cancelled, Collection $restored): string
    {
        $lines = $this->linesOf($cancelled, 'отменено')
            ->merge($this->linesOf($restored, 'снова в расписании'))
            ->merge($this->linesOf($changed, null));

        // Заголовок называет число сразу: при подрезанном списке человек должен
        // видеть, сколько занятий тронули, а не только первые десять.
        return MessageBody::list('Расписание изменилось, занятий: '.$lines->count(), $lines);
    }

    /**
     * @param Collection<int, ScheduleLesson|ScheduleEntry> $items
     * @param string|null $mark пометка вместо аудитории; `null` — обычная правка
     * @return BaseCollection<int, string>
     */
    private function linesOf(Collection $items, ?string $mark): BaseCollection
    {
        return $items
            ->sortBy(fn (ScheduleLesson|ScheduleEntry $item): string => $this->sortKey($item))
            ->map(function (ScheduleLesson|ScheduleEntry $item) use ($mark): string {
                $room = $item->classroom?->number;

                return trim(implode(' ', array_filter([
                    $this->dateOf($item)?->format('d.m') ?: '',
                    $item->starts_at ? substr((string) $item->starts_at, 0, 5) : '',
                    $item->subject?->name ?: 'Занятие',
                    $mark === null && $room ? "ауд. {$room}" : null,
                    $mark === null ? null : "— {$mark}",
                ])));
            })
            ->values()
            // `toBase()` обязателен: `merge()` у коллекции моделей ждёт модели, а здесь
            // уже строки, и она падает на `getKey()`.
            ->toBase();
    }

    /** У записи движка дата зовётся `date`, у строки расписания — `lesson_date`. */
    private function dateOf(ScheduleLesson|ScheduleEntry $item): ?CarbonInterface
    {
        return $item instanceof ScheduleEntry ? $item->date : $item->lesson_date;
    }

    private function sortKey(ScheduleLesson|ScheduleEntry $item): string
    {
        return ($this->dateOf($item)?->format('Y-m-d') ?: '').' '.substr((string) $item->starts_at, 0, 5);
    }
}
