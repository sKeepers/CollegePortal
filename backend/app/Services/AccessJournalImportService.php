<?php

namespace App\Services;

use App\Models\AccessEvent;
use App\Models\AccessPointDevice;
use App\Models\DigitalIdentity;
use App\Models\RfidCard;
use App\Support\Access\JournalRow;
use Illuminate\Support\Carbon;

/**
 * Загрузка журнала проходов из чужой системы.
 *
 * Три правила, каждое оплачено репетицией на копии действующей СКУД —
 * 50 337 событий, 16 793 прохода.
 *
 * **Первое: повтор не создаёт второй проход.** Ключ — пара «источник +
 * внешний идентификатор события», запись идёт через `insertOrIgnore`. Ловить
 * нарушение уникальности исключением здесь нельзя вдвойне: на PostgreSQL
 * упавший `INSERT` отравляет транзакцию целиком, а загрузка идёт пачками.
 *
 * **Второе: направление берётся у устройства и никогда не считается
 * чередованием.** Живое сканирование чередует вход и выход по прошлому
 * проходу человека — и это верно ровно до первой загрузки, потому что
 * привезённое событие встаёт в середину чужой цепочки. Замер на 16 436
 * разрешённых проходах копии действующей СКУД: чередование разошлось с
 * устройством 8 063 раза — **в половине журнала**, у 668 карт из 707. Причина
 * там же: 6 985 раз человек проходил подряд в одну сторону, и 6 372 из них —
 * два входа подряд. На выход турникет пускают свободно, картой отмечаются не
 * все, и чередование в таком журнале не ошибается по мелочи, а **выдумывает
 * выходы**, которых не было.
 *
 * **Третье: чего не понимаем — не грузим, а называем.** Событие с неизвестным
 * устройством не получает направление наугад: направление наугад врёт в
 * присутствии, опозданиях и ночных отсутствиях, и врёт молча. Непринятая
 * строка видна в отчёте, а внешний идентификатор делает её загрузку после
 * пополнения справочника безопасной — второй копии не будет.
 */
class AccessJournalImportService
{
    /** Пачка записи. Меньше — больше запросов, больше — дольше блокировка. */
    public const CHUNK = 500;

    /**
     * @param  iterable<JournalRow>  $rows
     * @param  bool  $collapseSourceDoubles  отбрасывать повторную запись одного
     *   физического прохода: та же карта, то же устройство, та же доля секунды
     */
    public function import(string $source, iterable $rows, bool $collapseSourceDoubles = false): AccessJournalImportReport
    {
        $report = new AccessJournalImportReport();
        $devices = $this->devices($source);
        $seen = [];
        $buffer = [];

        foreach ($rows as $row) {
            $report->received++;

            $device = $row->deviceId === null ? null : ($devices[$row->deviceId] ?? null);
            $report->countDevice($row->deviceId, $device !== null);

            if ($device === null) {
                $report->skippedUnknownDevice++;

                continue;
            }

            if ($device->direction === null) {
                $report->skippedUnknownDirection++;

                continue;
            }

            $key = $row->passKey();

            if (isset($seen[$key])) {
                $report->sourceDoubles++;

                if ($collapseSourceDoubles) {
                    $report->collapsed++;

                    continue;
                }
            }

            $seen[$key] = true;
            $buffer[] = [$row, $device];

            if (count($buffer) >= self::CHUNK) {
                $this->writeChunk($source, $buffer, $report);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $this->writeChunk($source, $buffer, $report);
        }

        return $report;
    }

    /** @return array<string, AccessPointDevice> */
    private function devices(string $source): array
    {
        return AccessPointDevice::query()
            ->with('accessPoint:id,name')
            ->where('source', $source)
            ->where('is_active', true)
            ->get()
            ->keyBy('external_id')
            ->all();
    }

    /**
     * @param  array<int, array{0: JournalRow, 1: AccessPointDevice}>  $buffer
     */
    private function writeChunk(string $source, array $buffer, AccessJournalImportReport $report): void
    {
        $owners = $this->owners(array_filter(array_map(
            static fn (array $pair): ?string => $pair[0]->cardUid,
            $buffer,
        )));

        $now = Carbon::now();
        $insert = [];

        foreach ($buffer as [$row, $device]) {
            $identity = $row->cardUid === null ? null : ($owners[$row->cardUid] ?? null);

            if ($row->cardUid === null) {
                $report->withoutCard++;
            } elseif ($identity === null) {
                $report->unresolvedCard++;
            } else {
                $report->resolved++;
            }

            $insert[] = [
                'external_source' => $source,
                'external_id' => $row->externalId,
                'card_uid' => $row->cardUid,
                'digital_identity_id' => $identity?->id,
                'access_point_id' => $device->access_point_id,
                'entity_type' => $identity?->entity_type,
                'entity_id' => $identity?->entity_id,
                'direction' => $device->direction,
                'event_time' => $row->eventTime,
                'access_point' => $device->accessPoint?->name,
                'device_name' => $device->name,
                'result' => $row->result,
                'reason' => $row->reason,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $inserted = AccessEvent::query()->insertOrIgnore($insert);

        $report->imported += $inserted;
        $report->alreadyPresent += count($insert) - $inserted;
    }

    /**
     * Владельцы карт пачкой.
     *
     * Здесь намеренно **не** повторяются правила `AccessCardResolver`. Тот
     * решает, пускать ли человека сейчас, и потому смотрит на состояние карты
     * и срок пропуска. Здесь решать уже нечего: контроллер решил, дверь
     * открылась или не открылась, а нам остаётся сказать, чей это был проход.
     * Проход по карте, которую после этого заблокировали, всё равно принадлежит
     * своему хозяину.
     *
     * @param  array<int, string>  $uids
     * @return array<string, DigitalIdentity>
     */
    private function owners(array $uids): array
    {
        $uids = array_values(array_unique($uids));

        if ($uids === []) {
            return [];
        }

        $cards = RfidCard::query()
            ->whereIn('uid', $uids)
            ->whereNotNull('person_id')
            ->pluck('person_id', 'uid');

        if ($cards->isEmpty()) {
            return [];
        }

        // Действующий пропуск важнее отозванного, а из отозванных берётся
        // последний: у человека за два года пропусков может быть несколько, и
        // проход обязан достаться тому, кто им был.
        $identities = DigitalIdentity::query()
            ->whereIn('person_id', $cards->values()->unique()->all())
            ->orderByRaw("case when status = ? then 0 else 1 end", [DigitalIdentity::STATUS_ACTIVE])
            ->orderByDesc('id')
            ->get()
            // `unique` оставляет первое вхождение, `keyBy` — последнее. Порядок
            // выше расставлен так, что первым идёт нужный пропуск, поэтому
            // сначала `unique`, и только потом ключ.
            ->unique('person_id')
            ->keyBy('person_id');

        $owners = [];

        foreach ($cards as $uid => $personId) {
            $identity = $identities->get($personId);

            if ($identity !== null) {
                $owners[$uid] = $identity;
            }
        }

        return $owners;
    }
}
