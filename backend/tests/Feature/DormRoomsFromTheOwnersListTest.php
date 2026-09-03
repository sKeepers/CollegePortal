<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\DormPlacement;
use App\Models\DormRoom;
use App\Models\Group;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Комнаты общежития по списку от коменданта.
 *
 * До 01.09.2026 в базе стояли пятнадцать заготовок с выдуманными номерами и
 * вместимостями, и в одну из них уже заселили двоих. Настоящий список пришёл от
 * владельца документом: 40 жилых блоков, 236 койко-мест, по 10 блоков и 59 мест
 * на каждом из этажей 2-5. К ним 03.09.2026 добавилась комната 312 на два места
 * — бывший кабинет воспитателя, владелец велел завести его жилым, — и потому
 * строк 41, мест 238, а на третьем этаже 11 строк и 61 место.
 *
 * Сорок — это блоки, а не комнаты: в блоке две жилые комнаты, кухня и санузел
 * (владелец, 01.09.2026). Деление мест между двумя комнатами он не назвал, и
 * портал держит блок одной строкой, пока не назовёт.
 *
 * Числа здесь — не пожелание, а сверка с тем документом. Список в команде
 * записан позициями (вместимость каждой позиции одинакова на всех четырёх
 * этажах), и сторож переводит эту запись обратно в итоговые числа: разойдись
 * они с документом — увидим здесь, а не на листе заселённости в среду.
 */
class DormRoomsFromTheOwnersListTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_list_adds_up_to_the_owners_document(): void
    {
        $this->dorm();

        $this->artisan('dorm:import-rooms')->assertSuccessful();

        $rooms = DormRoom::query()->get();

        $this->assertCount(41, $rooms, 'сорок блоков документа и комната 312');
        $this->assertSame(238, $rooms->sum('capacity'), '236 койко-мест документа и два места в 312');

        foreach ([2, 4, 5] as $floor) {
            $onTheFloor = $rooms->where('floor', $floor);
            $this->assertCount(10, $onTheFloor, "на этаже {$floor} десять блоков");
            $this->assertSame(59, $onTheFloor->sum('capacity'), "на этаже {$floor} 59 мест");
        }

        $third = $rooms->where('floor', 3);
        $this->assertCount(11, $third, 'на третьем этаже десять блоков и комната 312');
        $this->assertSame(61, $third->sum('capacity'), 'на третьем этаже 59 мест в блоках и два в 312');
    }

    public function test_it_does_not_create_the_non_residential_rooms(): void
    {
        // Этот сторож обязан оставаться зелёным: он охраняет то, что **не
        // должно** появиться, и потому не покраснеет ни на одном внесённом в
        // команду дефекте, кроме одного — попытки завести нежилое.
        //
        // Учебные классы 104, 105, 105А, 207, 212, 307 и четыре помещения около
        // 16,5 м² (407, 412, 507, 512) стоят в том же здании на Серова, 277, но
        // комнатами общежития не являются. Что они такое, владелец на
        // 03.09.2026 всё ещё не сказал; до его ответа их не заводят никуда.
        // Комната, в которой никто не живёт и жить не может, мешает коменданту
        // при заселении.
        //
        // Кабинет воспитателя 312 из этого списка ушёл 03.09.2026: владелец
        // велел завести его жилой комнатой. Он проверяется отдельно —
        // test_the_tutors_office_is_now_a_room.
        $this->dorm();

        $this->artisan('dorm:import-rooms')->assertSuccessful();

        foreach (['104', '105', '105А', '207', '212', '307', '407', '412', '507', '512'] as $number) {
            $this->assertNull(
                DormRoom::query()->firstWhere('number', $number),
                "{$number} — не комната общежития, заводить её нельзя",
            );
        }
    }

    public function test_the_tutors_office_is_now_a_room(): void
    {
        // До 03.09.2026 кабинет воспитателя 312 стоял в списке нежилых и не
        // заводился никуда. В тот день владелец велел завести его жилой
        // комнатой на два места — вместимость названа им, а не выведена нами
        // из площади: она решает, скольких портал пустит туда селиться.
        $this->dorm();

        $this->artisan('dorm:import-rooms')->assertSuccessful();

        $room = DormRoom::query()->firstWhere('number', '312');

        $this->assertNotNull($room, '312 заводится командой вместе с блоками, а не руками');
        $this->assertSame(2, $room->capacity, 'два места — по слову владельца от 03.09.2026');
        $this->assertSame(3, $room->floor, 'третий этаж');
        $this->assertSame(DormRoom::KIND_REGULAR, $room->kind, 'жилая комната, а не нежилое помещение');
        $this->assertTrue($room->is_active, 'в обращении: в неё можно селить');
    }

    public function test_a_placeholder_is_updated_and_not_duplicated(): void
    {
        $building = $this->dorm();
        $placeholder = DormRoom::create([
            'building_id' => $building->id,
            'number' => '204',
            'floor' => 2,
            'capacity' => 4,
            'kind' => DormRoom::KIND_REGULAR,
            'is_active' => true,
            'note' => 'ЗАГОТОВКА: номер и вместимость уточнить у коменданта',
        ]);

        $this->artisan('dorm:import-rooms')->assertSuccessful();

        $this->assertSame(41, DormRoom::query()->count(), 'заготовка обязана обновиться, а не встать рядом второй строкой');

        $placeholder->refresh();
        $this->assertSame(6, $placeholder->capacity, 'вместимость взята из списка владельца');
        $this->assertNull($placeholder->note, 'пометка заготовки снята');
    }

    public function test_the_residents_stay_in_their_room(): void
    {
        // В комнате 201 на 01.09.2026 уже живут двое, и по списку владельца в
        // ней три места. Обновление обязано сохранить и строку комнаты, и
        // заселения: они висят на её идентификаторе, и новая строка увела бы
        // людей в никуда.
        $building = $this->dorm();
        $room = DormRoom::create([
            'building_id' => $building->id,
            'number' => '201',
            'floor' => 2,
            'capacity' => 3,
            'kind' => DormRoom::KIND_REGULAR,
            'is_active' => true,
            'note' => 'ЗАГОТОВКА: номер и вместимость уточнить у коменданта',
        ]);

        foreach (['Первый', 'Второй'] as $lastName) {
            DormPlacement::create([
                'dorm_room_id' => $room->id,
                'student_id' => $this->student($lastName)->id,
                'moved_in_at' => '2026-09-01',
            ]);
        }

        $this->artisan('dorm:import-rooms')->assertSuccessful();

        $room->refresh();
        $this->assertSame(3, $room->capacity);
        $this->assertSame(2, $room->currentPlacements()->count(), 'жильцы остались в той же комнате');
    }

    public function test_a_room_fuller_than_the_new_capacity_stops_the_whole_run(): void
    {
        // Молча уменьшить вместимость ниже числа живущих — значит сделать
        // комнату переполненной: портал потом откажет коменданту в заселении по
        // причине, которой он не создавал. Отказ идёт по всей команде, а не
        // пропуском строки, иначе в базе осталась бы половина списка.
        $building = $this->dorm();
        $room = DormRoom::create([
            'building_id' => $building->id,
            'number' => '201',
            'floor' => 2,
            'capacity' => 4,
            'kind' => DormRoom::KIND_REGULAR,
            'is_active' => true,
        ]);

        foreach (['Первый', 'Второй', 'Третий', 'Четвёртый'] as $lastName) {
            DormPlacement::create([
                'dorm_room_id' => $room->id,
                'student_id' => $this->student($lastName)->id,
                'moved_in_at' => '2026-09-01',
            ]);
        }

        $this->artisan('dorm:import-rooms')->assertFailed();

        $this->assertSame(1, DormRoom::query()->count(), 'при отказе не заводится ни одной комнаты');
        $this->assertSame(4, $room->refresh()->capacity, 'вместимость не тронута');
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        // Команду зовут руками, и позовут её не раз: на стенде мы, на боевом
        // владелец. Второй прогон обязан быть безобидным.
        $this->dorm();

        $this->artisan('dorm:import-rooms')->assertSuccessful();
        $first = DormRoom::query()->orderBy('number')->get(['number', 'floor', 'capacity'])->toArray();

        $this->artisan('dorm:import-rooms')->assertSuccessful();
        $second = DormRoom::query()->orderBy('number')->get(['number', 'floor', 'capacity'])->toArray();

        $this->assertSame($first, $second);
        $this->assertSame(41, DormRoom::query()->count());
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->dorm();

        $this->artisan('dorm:import-rooms', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, DormRoom::query()->count(), 'пробный прогон не оставляет следов');
    }

    /** Здание общежития заводится миграцией — здесь оно только находится. */
    private function dorm(): Building
    {
        return Building::query()->firstWhere('code', 'SER277') ?? Building::create([
            'name' => 'Общежитие на Серова, 277',
            'code' => 'SER277',
            'is_active' => true,
        ]);
    }

    private function student(string $lastName): Student
    {
        $group = Group::query()->firstWhere('name', 'ИСП-101') ?? Group::create([
            'name' => 'ИСП-101',
            'specialty' => 'Инструментальное исполнительство',
            'course' => 1,
            'year_start' => 2026,
        ]);

        return Student::create([
            'group_id' => $group->id,
            'last_name' => $lastName,
            'first_name' => 'Иван',
            'status' => 'active',
        ]);
    }
}
