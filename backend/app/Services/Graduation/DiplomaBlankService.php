<?php

namespace App\Services\Graduation;

use App\Models\Diploma;
use App\Models\DiplomaBlank;
use App\Models\DiplomaBlankBatch;
use App\Models\DiplomaBlankEvent;
use App\Models\Graduate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Учёт бланков строгой отчётности.
 *
 * Правила, из-за которых служба выглядит строже обычного CRUD:
 *
 * 1. **Партия принимается целиком или не принимается вовсе.** Если хоть один
 *    номер диапазона уже заведён, отказ идёт на всю партию с перечнем
 *    столкнувшихся номеров. Частично принятая партия хуже непринятой: остаток
 *    по накладной не сойдётся, а понять, чего не хватает, будет неоткуда.
 * 2. **Номер — строка, а не число.** У гознака номера с ведущими нулями, и
 *    `0000123` не равно `123`. Ширина берётся от длинного конца диапазона.
 * 3. **Из состояния в состояние — только по разрешённому переходу.** Выданный
 *    и списанный бланк не меняются больше никак: это конец пути.
 * 4. **Ничего не удаляется.** Испорченный бланк остаётся в книге с причиной,
 *    списание только добавляет к нему номер акта. Запрет стоит в самих
 *    моделях.
 *
 * Каждое изменение состояния пишет строку в `diploma_blank_events`, и пишет её
 * в той же транзакции: состояние без движения — это состояние, о котором никто
 * не скажет, откуда оно взялось.
 */
class DiplomaBlankService
{
    /**
     * Партия за раз. Больше — почти наверняка опечатка в диапазоне, а не
     * поставка: на курс уходит две-три сотни бланков.
     */
    public const MAX_BATCH = 2000;

    /** Из какого состояния куда можно. Пустой список — конец пути. */
    private const TRANSITIONS = [
        DiplomaBlank::STATUS_STOCK => [
            DiplomaBlank::STATUS_ASSIGNED,
            DiplomaBlank::STATUS_SPOILED,
        ],
        DiplomaBlank::STATUS_ASSIGNED => [
            DiplomaBlank::STATUS_ISSUED,
            DiplomaBlank::STATUS_STOCK,
            DiplomaBlank::STATUS_SPOILED,
        ],
        DiplomaBlank::STATUS_ISSUED => [],
        DiplomaBlank::STATUS_SPOILED => [
            DiplomaBlank::STATUS_WRITTEN_OFF,
        ],
        DiplomaBlank::STATUS_WRITTEN_OFF => [],
    ];

    /**
     * Принять партию: развернуть диапазон в отдельные бланки.
     *
     * @param  array<string, mixed>  $data
     */
    public function receive(array $data, ?int $userId = null): DiplomaBlankBatch
    {
        $kind = (string) $data['kind'];
        $series = trim((string) $data['series']);
        $numbers = $this->expand((string) $data['number_from'], (string) $data['number_to']);

        $this->assertNumbersAreFree($kind, $series, $numbers);

        return DB::transaction(function () use ($data, $kind, $series, $numbers, $userId): DiplomaBlankBatch {
            $batch = DiplomaBlankBatch::create([
                'kind' => $kind,
                'series' => $series,
                'number_from' => $numbers[0],
                'number_to' => $numbers[count($numbers) - 1],
                'quantity' => count($numbers),
                'received_at' => $data['received_at'],
                'supplier' => $data['supplier'] ?? null,
                'invoice_number' => $data['invoice_number'] ?? null,
                'received_by_user_id' => $userId,
                'note' => $data['note'] ?? null,
            ]);

            $now = now();

            // Пакетная вставка, а не `create()` в цикле и тем более не
            // `updateOrCreate`: последний внутри транзакции открывает точку
            // сохранения на каждую строку, а таблица блокировок на сервере одна.
            // На двух тысячах бланков это половина её ёмкости.
            foreach (array_chunk($numbers, 500) as $chunk) {
                DiplomaBlank::insert(array_map(fn (string $number): array => [
                    'diploma_blank_batch_id' => $batch->id,
                    'kind' => $kind,
                    'series' => $series,
                    'number' => $number,
                    'status' => DiplomaBlank::STATUS_STOCK,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            $blankIds = DiplomaBlank::where('diploma_blank_batch_id', $batch->id)->pluck('id');

            foreach ($blankIds->chunk(500) as $chunk) {
                DiplomaBlankEvent::insert($chunk->map(fn (int $id): array => [
                    'diploma_blank_id' => $id,
                    'action' => DiplomaBlankEvent::ACTION_RECEIVED,
                    'from_status' => null,
                    'to_status' => DiplomaBlank::STATUS_STOCK,
                    'user_id' => $userId,
                    'happened_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            }

            return $batch;
        });
    }

    /** Закрепить бланк за выпускником. */
    public function assign(DiplomaBlank $blank, Graduate $graduate, ?int $userId = null, ?string $note = null): DiplomaBlank
    {
        $this->assertTransition($blank, DiplomaBlank::STATUS_ASSIGNED);
        $this->assertGraduateHasNoLiveBlank($blank, $graduate);

        return DB::transaction(function () use ($blank, $graduate, $userId, $note): DiplomaBlank {
            $diploma = $graduate->diploma;

            if ($diploma !== null && $blank->kind !== DiplomaBlank::KIND_SUPPLEMENT) {
                $this->writeBlankIntoDiploma($blank, $diploma);
            }

            $this->move($blank, DiplomaBlankEvent::ACTION_ASSIGNED, DiplomaBlank::STATUS_ASSIGNED, [
                'graduate_id' => $graduate->id,
                'diploma_id' => $diploma?->id,
                'assigned_at' => now()->toDateString(),
                'note' => $note,
            ], $userId, $graduate->id);

            return $blank;
        });
    }

    /** Снять закрепление: ошиблись номером, выпускник отказался. Бланк цел и возвращается в наличие. */
    public function release(DiplomaBlank $blank, ?int $userId = null, ?string $reason = null): DiplomaBlank
    {
        $this->assertTransition($blank, DiplomaBlank::STATUS_STOCK);

        $this->move($blank, DiplomaBlankEvent::ACTION_RELEASED, DiplomaBlank::STATUS_STOCK, [
            'graduate_id' => null,
            'diploma_id' => null,
            'assigned_at' => null,
        ], $userId, null, null, $reason);

        return $blank;
    }

    /** Выдать на руки. Дальше бланк не меняется. */
    public function issue(DiplomaBlank $blank, ?string $issuedAt = null, ?int $userId = null): DiplomaBlank
    {
        $this->assertTransition($blank, DiplomaBlank::STATUS_ISSUED);

        if ($blank->graduate_id === null) {
            throw ValidationException::withMessages([
                'graduate_id' => 'Выдать можно только бланк, закреплённый за выпускником.',
            ]);
        }

        $this->move($blank, DiplomaBlankEvent::ACTION_ISSUED, DiplomaBlank::STATUS_ISSUED, [
            'issued_at' => $issuedAt ?: now()->toDateString(),
        ], $userId, $blank->graduate_id);

        return $blank;
    }

    /**
     * Отметить испорченным.
     *
     * Причина обязательна: «испорчен» без причины — это пропавший бланк с
     * пометкой, а не отчёт.
     */
    public function spoil(DiplomaBlank $blank, string $reason, ?int $userId = null): DiplomaBlank
    {
        $this->assertTransition($blank, DiplomaBlank::STATUS_SPOILED);

        $this->move($blank, DiplomaBlankEvent::ACTION_SPOILED, DiplomaBlank::STATUS_SPOILED, [
            'spoiled_at' => now()->toDateString(),
            'reason' => $reason,
        ], $userId, $blank->graduate_id, null, $reason);

        return $blank;
    }

    /** Списать актом. Списывается только испорченное — целый бланк списывать нечем и незачем. */
    public function writeOff(DiplomaBlank $blank, string $actNumber, ?string $reason = null, ?int $userId = null): DiplomaBlank
    {
        $this->assertTransition($blank, DiplomaBlank::STATUS_WRITTEN_OFF);

        $this->move($blank, DiplomaBlankEvent::ACTION_WRITTEN_OFF, DiplomaBlank::STATUS_WRITTEN_OFF, [
            'written_off_at' => now()->toDateString(),
            'write_off_act' => $actNumber,
        ], $userId, $blank->graduate_id, $actNumber, $reason);

        return $blank;
    }

    /**
     * Остаток по видам и состояниям — то, ради чего учёт и заводится.
     *
     * @return array<int, array<string, mixed>>
     */
    public function balance(): array
    {
        $rows = DiplomaBlank::query()
            ->selectRaw('kind, series, status, count(*) as total')
            ->groupBy('kind', 'series', 'status')
            ->orderBy('kind')
            ->orderBy('series')
            ->get();

        $balance = [];

        foreach ($rows as $row) {
            $key = $row->kind.'|'.$row->series;
            $balance[$key] ??= ['kind' => $row->kind, 'series' => $row->series, 'total' => 0]
                + array_fill_keys(DiplomaBlank::STATUSES, 0);
            $balance[$key][$row->status] = (int) $row->total;
            $balance[$key]['total'] += (int) $row->total;
        }

        return array_values($balance);
    }

    /**
     * Развернуть диапазон номеров.
     *
     * @return list<string>
     */
    private function expand(string $from, string $to): array
    {
        $from = trim($from);
        $to = trim($to);

        foreach (['number_from' => $from, 'number_to' => $to] as $field => $value) {
            if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
                throw ValidationException::withMessages([
                    $field => 'Номер бланка состоит только из цифр: ведущие нули значимы и сохраняются.',
                ]);
            }
        }

        $start = (int) $from;
        $end = (int) $to;

        if ($end < $start) {
            throw ValidationException::withMessages([
                'number_to' => 'Конец диапазона меньше начала.',
            ]);
        }

        $count = $end - $start + 1;

        if ($count > self::MAX_BATCH) {
            throw ValidationException::withMessages([
                'number_to' => sprintf(
                    'В диапазоне %d бланков, а за раз принимается не больше %d. Похоже на опечатку в номере.',
                    $count,
                    self::MAX_BATCH,
                ),
            ]);
        }

        // Ширина от длинного конца: «0000123»–«0000222» остаются семизначными.
        $width = max(strlen($from), strlen($to));

        $numbers = [];

        for ($value = $start; $value <= $end; $value++) {
            $numbers[] = str_pad((string) $value, $width, '0', STR_PAD_LEFT);
        }

        return $numbers;
    }

    /** @param  list<string>  $numbers */
    private function assertNumbersAreFree(string $kind, string $series, array $numbers): void
    {
        $taken = [];

        foreach (array_chunk($numbers, 1000) as $chunk) {
            $taken = array_merge($taken, DiplomaBlank::query()
                ->where('kind', $kind)
                ->where('series', $series)
                ->whereIn('number', $chunk)
                ->pluck('number')
                ->all());
        }

        if ($taken === []) {
            return;
        }

        sort($taken);
        $shown = array_slice($taken, 0, 10);

        throw ValidationException::withMessages([
            'number_from' => sprintf(
                'Партия не принята целиком: %d номеров этой серии уже заведены (%s%s). Частично принятая партия не сойдётся с накладной.',
                count($taken),
                implode(', ', $shown),
                count($taken) > count($shown) ? ' и другие' : '',
            ),
        ]);
    }

    private function assertTransition(DiplomaBlank $blank, string $to): void
    {
        $allowed = self::TRANSITIONS[$blank->status] ?? [];

        if (in_array($to, $allowed, true)) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => sprintf(
                'Бланк %s: из состояния «%s» перейти в «%s» нельзя.',
                $blank->label(),
                $this->statusLabel($blank->status),
                $this->statusLabel($to),
            ),
        ]);
    }

    /**
     * Серия и номер бланка попадают в диплом, но не затирают уже стоящие там.
     *
     * Расхождение — это либо ошибка в закреплении, либо диплом уже выписан на
     * другой бланк. Молча переписать значит потерять один из двух номеров.
     */
    private function writeBlankIntoDiploma(DiplomaBlank $blank, Diploma $diploma): void
    {
        $hasOwn = filled($diploma->series) || filled($diploma->number);
        $sameBlank = $diploma->series === $blank->series && $diploma->number === $blank->number;

        // Номер, оставшийся в дипломе от **испорченного** бланка, замене не
        // мешает: ради этого замена и делается. Испорченный бланк при этом
        // никуда не девается — он остаётся в книге со своим номером и причиной,
        // просто перестаёт быть номером этого диплома.
        if ($hasOwn && ! $sameBlank && $this->numberBelongsToARuinedBlank($diploma)) {
            $hasOwn = false;
        }

        if ($hasOwn && ! $sameBlank) {
            throw ValidationException::withMessages([
                'diploma_id' => sprintf(
                    'В дипломе уже стоит бланк %s %s, а закрепляется %s. Разберитесь, какой из двух верен.',
                    (string) $diploma->series,
                    (string) $diploma->number,
                    $blank->label(),
                ),
            ]);
        }

        $diploma->fill(['series' => $blank->series, 'number' => $blank->number])->save();
    }

    /**
     * Изменить состояние и записать движение одной транзакцией.
     *
     * @param  array<string, mixed>  $changes
     */
    private function move(
        DiplomaBlank $blank,
        string $action,
        string $to,
        array $changes,
        ?int $userId,
        ?int $graduateId = null,
        ?string $actNumber = null,
        ?string $reason = null,
    ): void {
        $from = $blank->status;

        DB::transaction(function () use ($blank, $action, $to, $changes, $userId, $graduateId, $actNumber, $reason, $from): void {
            $blank->fill($changes + ['status' => $to])->save();

            DiplomaBlankEvent::create([
                'diploma_blank_id' => $blank->id,
                'action' => $action,
                'from_status' => $from,
                'to_status' => $to,
                'graduate_id' => $graduateId,
                'user_id' => $userId,
                'act_number' => $actNumber,
                'reason' => $reason,
                'happened_at' => Carbon::now(),
            ]);
        });
    }

    /**
     * У выпускника не бывает двух живых бланков одного вида.
     *
     * Испорченный и списанный не считаются: замена испорченного — обычное дело,
     * ради неё половина учёта и заводится. А вот второй закреплённый или второй
     * выданный диплом у одного человека — это либо ошибка в номере, либо
     * настоящая беда, и разбирать её должен человек.
     */
    private function assertGraduateHasNoLiveBlank(DiplomaBlank $blank, Graduate $graduate): void
    {
        $live = DiplomaBlank::query()
            ->where('graduate_id', $graduate->id)
            ->where('kind', $blank->kind)
            ->whereKeyNot($blank->id)
            ->whereIn('status', [DiplomaBlank::STATUS_ASSIGNED, DiplomaBlank::STATUS_ISSUED])
            ->first();

        if ($live === null) {
            return;
        }

        throw ValidationException::withMessages([
            'graduate_id' => sprintf(
                'За выпускником уже закреплён бланк %s в состоянии «%s». Испорченный сначала отмечают испорченным, и только потом закрепляют новый.',
                $live->label(),
                $this->statusLabel($live->status),
            ),
        ]);
    }

    /** Стоит ли в дипломе номер бланка, который к этому времени испорчен или списан. */
    private function numberBelongsToARuinedBlank(Diploma $diploma): bool
    {
        return DiplomaBlank::query()
            ->where('series', $diploma->series)
            ->where('number', $diploma->number)
            ->whereIn('status', [DiplomaBlank::STATUS_SPOILED, DiplomaBlank::STATUS_WRITTEN_OFF])
            ->exists();
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            DiplomaBlank::STATUS_STOCK => 'в наличии',
            DiplomaBlank::STATUS_ASSIGNED => 'закреплён',
            DiplomaBlank::STATUS_ISSUED => 'выдан',
            DiplomaBlank::STATUS_SPOILED => 'испорчен',
            DiplomaBlank::STATUS_WRITTEN_OFF => 'списан',
            default => $status,
        };
    }
}
