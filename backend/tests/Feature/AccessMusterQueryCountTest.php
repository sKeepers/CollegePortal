<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\DigitalIdentity;
use App\Models\Group;
use App\Models\Student;
use App\Services\AccessPresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Поимённый список эвакуации: число запросов к базе не зависит от числа людей.
 *
 * Находка 13 аудита от 08.08.2026. `AccessEvent::owner` — не связь, а аксессор,
 * который ходит в базу при каждом обращении, поэтому список делал запрос на
 * каждого человека. Замер на стенде 10.08.2026: 598 человек в здании — 1129
 * запросов и 684 мс.
 *
 * Тест считает запросы, а не время: время на стенде и в CI разное, а
 * зависимость числа запросов от числа людей — то самое свойство, которое
 * сломалось и не должно сломаться снова.
 */
class AccessMusterQueryCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_muster_does_not_query_per_person(): void
    {
        $this->putStudentsInside(5);
        $fewQueries = $this->countQueries();

        $this->putStudentsInside(45);
        $manyQueries = $this->countQueries();

        $muster = app(AccessPresenceService::class)->musterByBuilding();
        $this->assertSame(50, collect($muster)->sum('people_count'), 'В здании должны оказаться все пятьдесят');

        // Десятикратный рост числа людей не должен давать роста числа запросов.
        // До исправления было почти два запроса на человека, то есть 10 и 100.
        $this->assertSame(
            $fewQueries,
            $manyQueries,
            "Запросов на пятерых: {$fewQueries}, на пятидесяти: {$manyQueries}. Список снова ходит в базу за каждым человеком.",
        );
    }

    /**
     * Имена и группы в списке должны остаться на месте: замена запроса на
     * человека выборкой пачкой не имеет права обеднить сам список.
     */
    public function test_muster_still_names_people_and_their_groups(): void
    {
        $this->putStudentsInside(3);

        $people = collect(app(AccessPresenceService::class)->musterByBuilding())
            ->flatMap(fn (array $building): array => $building['people']);

        $this->assertCount(3, $people);
        $this->assertNotEmpty($people->first()['full_name']);
        $this->assertSame('ИСП-101', $people->first()['group']);
        $this->assertNotNull($people->first()['entered_at']);
    }

    private function countQueries(): int
    {
        $service = app(AccessPresenceService::class);
        $service->musterByBuilding();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->musterByBuilding();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function putStudentsInside(int $count): void
    {
        $group = Group::query()->firstOrCreate(
            ['name' => 'ИСП-101'],
            ['specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026],
        );

        foreach (range(1, $count) as $index) {
            $student = Student::create([
                'group_id' => $group->id,
                'last_name' => 'Студент'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'first_name' => 'Имя',
                'status' => 'active',
            ]);

            $identity = DigitalIdentity::create([
                'entity_type' => 'student',
                'entity_id' => $student->id,
                'token' => (string) Str::uuid(),
                'status' => 'active',
                'issued_at' => now(),
            ]);

            AccessEvent::create([
                'digital_identity_id' => $identity->id,
                'entity_type' => 'student',
                'entity_id' => $student->id,
                'direction' => AccessEvent::DIRECTION_IN,
                'event_time' => now()->setTime(8, 30),
                'result' => AccessEvent::RESULT_ALLOWED,
            ]);
        }
    }
}
