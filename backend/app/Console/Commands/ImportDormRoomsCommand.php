<?php

namespace App\Console\Commands;

use App\Models\Building;
use App\Models\DormRoom;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Комнаты общежития по списку помещений от коменданта.
 *
 * Команда, а не миграция: это данные конкретного колледжа, а не каталог
 * системы. Миграция выполнилась бы на боевом сервере сама при первом же
 * обновлении, а владелец просит переносить данные отдельно и сам. Команду
 * зовут руками — на стенде мы, на боевом он.
 *
 * **Блок — не комната, и делится он по-разному.** Владелец 04.09.2026 назвал
 * деление: **6 мест — две комнаты по 3+3, 8 мест — три комнаты по 3+3+2.** Про
 * блок на 3 места он не сказал ничего, и гадать не пришлось — это одна комната,
 * и следует это из его же прежнего числа: жилых комнат в общежитии **80**
 * (01.09.2026). При одной комнате в трёхместном блоке на этаж выходит
 * 1 + 8×2 + 3 = 20 комнат, всего 80; при двух — 84. Сходится только первое.
 *
 * Номер комнаты — номер блока с русской буквой: `202а`, `202б`, у восьмиместного
 * ещё и `202в`. Одиночная комната трёхместного блока номер не меняет: `201`.
 *
 * **Лист на дверь при этом остаётся на блок** — так владелец ответил 03.09.2026
 * дословно: «Делится на блоки». Комнаты нужны для заселения, а не для двери:
 * коменданту надо знать, в какой из двух комнат место, а на дверь блока идёт
 * один лист со всеми его жильцами.
 *
 * До неё в базе стояли пятнадцать заготовок с пометкой «ЗАГОТОВКА: номер и
 * вместимость уточнить у коменданта», и в одну из них уже заселили двоих.
 * Поэтому команда **обновляет по номеру, а не заводит заново**: номер уникален
 * в паре со зданием, заселения висят на строке комнаты, и новая строка увела
 * бы жильцов в никуда.
 */
class ImportDormRoomsCommand extends Command
{
    protected $signature = 'dorm:import-rooms
        {--building=SER277 : код здания общежития}
        {--dry-run : посчитать и откатить, ничего не записав}';

    protected $description = 'Завести комнаты общежития по списку помещений от коменданта';

    /**
     * Жилые блоки: позиция в номере => койко-мест **в блоке целиком**.
     *
     * Записано позициями, а не сорока строками, потому что документ владельца
     * так и устроен — замер 01.09.2026 по всем 57 абзацам: вместимость каждой
     * позиции **одинакова на всех четырёх этажах**, и первая цифра номера
     * всегда равна этажу. Проверяется в один взгляд против самого документа, а
     * итоговые 40 блоков и 236 мест закреплены сторожем. Строк в базе больше:
     * блок делится на комнаты (см. ROOMS_IN_BLOCK), и к 80 комнатам добавляется
     * 312 — см. SINGLE_ROOMS.
     *
     * Позиции 07 в жилых нет **ни на одном** этаже: на 2 и 3 там учебный класс
     * (207, 307), на 4 и 5 — помещение около 16,5 м² (407, 507). Нежилые
     * помещения команда не заводит — кроме одного: кабинет воспитателя 312
     * владелец 03.09.2026 велел завести жилой комнатой, и он стоит в
     * SINGLE_ROOMS. Про остальные он по-прежнему не сказал, что они такое, а
     * комната общежития, в которой никто не живёт и жить не может, будет
     * мешать коменданту при заселении.
     */
    private const PLACES = [
        '01' => 3,
        '02' => 6,
        '03' => 6,
        '04' => 6,
        '05' => 6,
        '06' => 6,
        '08' => 8,
        '09' => 6,
        '10' => 6,
        '11' => 6,
    ];

    /** Жилые этажи. Первый занят учебными классами целиком. */
    private const FLOORS = [2, 3, 4, 5];

    /**
     * Как блок делится на комнаты: мест в блоке => места по комнатам.
     *
     * Владелец 04.09.2026, дословно: «6 это в одном блоке две комнаты по 3+3
     * человека; 8 это в одном блоке три комнаты по 3+3+2 человека». Про
     * трёхместный блок он не сказал, и здесь стоит одна комната — не догадка, а
     * следствие его же числа 80 жилых комнат: при делении надвое их было бы 84.
     *
     * Сумма по каждому блоку обязана совпасть с числом мест в PLACES, иначе
     * итог разойдётся с документом владельца. Это проверяется в rooms().
     */
    private const ROOMS_IN_BLOCK = [
        3 => [3],
        6 => [3, 3],
        8 => [3, 3, 2],
    ];

    /** Буквы комнат в блоке. Русские: номер читает человек, а не машина. */
    private const LETTERS = ['а', 'б', 'в'];

    /**
     * Комнаты, заведённые поимённо, а не позицией.
     *
     * 312 — бывший кабинет воспитателя. Владелец 03.09.2026: завести жилой
     * комнатой, мест два. Число его, не наше: вместимость решает, скольких
     * портал пустит туда селиться, и гадать по площади было бы враньём.
     *
     * Отдельным списком, а не позицией «12» в PLACES: позиция размножилась бы
     * на все четыре этажа, а 212, 412 и 512 — нежилые.
     */
    private const SINGLE_ROOMS = [
        ['number' => '312', 'floor' => 3, 'capacity' => 2],
    ];

    /** Пометка, которой были подписаны заготовки. Свои заметки коменданта не трогаем. */
    private const PLACEHOLDER_NOTE = 'ЗАГОТОВКА';

    public function handle(): int
    {
        $code = (string) $this->option('building');
        $building = Building::query()->firstWhere('code', $code);

        if ($building === null) {
            $this->error("Здание с кодом {$code} не найдено. Общежитие — SER277.");

            return self::FAILURE;
        }

        $rooms = $this->rooms();
        $this->line("Здание: {$building->name}");
        $this->line('В списке: комнат '.count($rooms).', мест '.array_sum(array_column($rooms, 'capacity')));

        $existing = DormRoom::query()
            ->where('building_id', $building->id)
            ->withCount('currentPlacements')
            ->get()
            ->keyBy('number');

        $renamed = $this->renameBlocksIntoFirstRooms($rooms, $existing);

        if (($stop = $this->tooFullForTheNewCapacity($rooms, $existing)) !== []) {
            $this->error('Отказ: в этих комнатах живут больше, чем даёт новая вместимость.');
            foreach ($stop as $line) {
                $this->line('  '.$line);
            }
            $this->line('Сначала переселите людей, потом повторите — иначе портал откажет коменданту в заселении по причине, которой он не создавал.');

            return self::FAILURE;
        }

        // Комнаты, которых в списке нет, — только называем, и до записи, чтобы
        // это было видно и в пробном прогоне. Удалять их нельзя: за комнатой
        // стоит история заселений, и портал их намеренно не удаляет.
        $extra = $existing->keys()->diff(array_column($rooms, 'number'));
        if ($extra->isNotEmpty()) {
            $this->warn('В базе есть комнаты, которых нет в списке: '.$extra->implode(', '));
            $this->line('Команда их не трогает. Если их больше нет — выведите из обращения в карточке комнаты.');
        }

        if ($renamed !== []) {
            $this->line('Строки блоков стали первыми комнатами: '.implode(', ', $renamed));
            $this->line('Жильцы остались на своих строках и оказались в комнате «а».');
        }

        $dryRun = (bool) $this->option('dry-run');
        $created = 0;
        $updated = 0;
        $same = 0;

        // Транзакция ведётся руками, а не через DB::transaction: пробный прогон
        // откатывает записанное, а замыкание закоммитило бы его раньше отката.
        DB::beginTransaction();

        try {
            foreach ($rooms as $row) {
                $room = $existing->get($row['number']);

                if ($room === null) {
                    // firstOrNew + save, а не updateOrCreate: последний внутри
                    // транзакции открывает точку сохранения на каждую строку, а
                    // таблица блокировок PostgreSQL одна на весь сервер.
                    $room = new DormRoom(['building_id' => $building->id, 'number' => $row['number']]);
                    $room->fill($row + ['kind' => DormRoom::KIND_REGULAR, 'is_active' => true]);
                    $room->save();
                    $created++;

                    continue;
                }

                $room->fill($row);

                if (str_starts_with((string) $room->note, self::PLACEHOLDER_NOTE)) {
                    $room->note = null;
                }

                if ($room->isDirty()) {
                    $room->save();
                    $updated++;
                } else {
                    $same++;
                }
            }
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        if ($dryRun) {
            DB::rollBack();
            $this->line("Завелось бы: {$created}, обновилось бы: {$updated}, без изменений: {$same}");
            $this->warn('Пробный прогон: записанное откачено.');

            return self::SUCCESS;
        }

        DB::commit();

        $this->line("Заведено: {$created}, обновлено: {$updated}, без изменений: {$same}");

        $this->total($building->id);

        return self::SUCCESS;
    }

    /**
     * Строка блока становится первой комнатой блока.
     *
     * До 04.09.2026 блок стоял одной строкой — «202», шесть мест. Теперь его
     * место занимают «202а» и «202б». Завести новые строки и бросить старую
     * нельзя: **на строке комнаты висят заселения**, и брошенная строка увела
     * бы жильцов в никуда, а в списке коменданта осталась бы комната-призрак,
     * в которую он стал бы селить.
     *
     * Поэтому старая строка переименовывается в первую комнату блока и
     * получает её вместимость, а остальные комнаты заводятся рядом. Жильцы
     * остаются на той же строке и оказываются в комнате «а».
     *
     * @param  list<array{number: string, floor: int, capacity: int}>  $rooms
     * @return list<string> что было переименовано — для вывода
     */
    private function renameBlocksIntoFirstRooms(array $rooms, $existing): array
    {
        $renamed = [];

        foreach ($rooms as $row) {
            $number = $row['number'];
            $letter = mb_substr($number, -1);

            if ($letter !== self::LETTERS[0]) {
                continue;
            }

            $block = mb_substr($number, 0, -1);

            if ($existing->has($number) || ! $existing->has($block)) {
                continue;
            }

            $room = $existing->get($block);
            $room->number = $number;
            $room->save();

            $existing->put($number, $room);
            $existing->forget($block);
            $renamed[] = $block.' → '.$number;
        }

        return $renamed;
    }

    /** Список комнат по документу: номер, этаж, вместимость. */
    private function rooms(): array
    {
        $rooms = [];

        foreach (self::FLOORS as $floor) {
            foreach (self::PLACES as $position => $capacity) {
                $block = $floor.$position;
                $split = self::ROOMS_IN_BLOCK[$capacity] ?? null;

                if ($split === null || array_sum($split) !== $capacity) {
                    // Отказ, а не молчаливый пропуск: разойдись деление с
                    // числом мест — итог разойдётся с документом владельца, и
                    // заметить это можно будет только по листу заселённости.
                    throw new RuntimeException(
                        "Блок {$block}: в списке {$capacity} мест, а деление на комнаты даёт "
                        .($split === null ? 'неизвестно сколько' : array_sum($split))
                        .'. Поправьте ROOMS_IN_BLOCK.'
                    );
                }

                foreach ($split as $i => $places) {
                    // Одна комната в блоке — номер без буквы: «201», а не
                    // «201а». Буква нужна, только когда есть что различать.
                    $rooms[] = [
                        'number' => count($split) === 1 ? $block : $block.self::LETTERS[$i],
                        'floor' => $floor,
                        'capacity' => $places,
                    ];
                }
            }
        }

        foreach (self::SINGLE_ROOMS as $room) {
            $rooms[] = $room;
        }

        return $rooms;
    }

    /**
     * Комнаты, где живут больше, чем даст новая вместимость.
     *
     * Отказ по всей команде, а не пропуск строки: половина списка, записанная
     * при отказавшей второй половине, хуже, чем не записанная вовсе — числа на
     * листе заселённости сойдутся, а комнат будет не сорок.
     */
    private function tooFullForTheNewCapacity(array $rooms, $existing): array
    {
        $stop = [];

        foreach ($rooms as $row) {
            $room = $existing->get($row['number']);

            if ($room !== null && $room->current_placements_count > $row['capacity']) {
                $stop[] = "комната {$room->number}: живут {$room->current_placements_count}, по списку мест {$row['capacity']}";
            }
        }

        return $stop;
    }

    private function total(int $buildingId): void
    {
        $rooms = DormRoom::query()->where('building_id', $buildingId);

        $this->line('Стало: комнат '.(clone $rooms)->count()
            .', мест '.(clone $rooms)->sum('capacity')
            .', занято '.(clone $rooms)->withCount('currentPlacements')->get()->sum('current_placements_count'));
    }
}
