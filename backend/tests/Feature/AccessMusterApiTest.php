<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\AccessPoint;
use App\Models\Building;
use App\Models\DigitalIdentity;
use App\Models\Group;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\QrSvgService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Список эвакуации: кто сейчас в каком корпусе. Проверяется, что он строится по
 * тому же правилу, что и счетчик «сейчас в здании», иначе на эвакуации будут два
 * разных ответа на один вопрос.
 */
class AccessMusterApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $now = CarbonImmutable::parse('2026-09-10 09:15:00');
        CarbonImmutable::setTestNow($now);
        Carbon::setTestNow($now);

        $this->withApiAuth();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_scan_binds_the_event_to_the_access_point_by_name(): void
    {
        $main = $this->building('Главный корпус', 'Главный вход');
        $identity = $this->studentIdentity('Иванов', 'Дмитрий');

        $this->scan($identity, 'главный вход  ')
            ->assertOk()
            ->assertJsonPath('data.access_point_id', $main['point']->id)
            ->assertJsonPath('data.building_name', 'Главный корпус');
    }

    public function test_unknown_access_point_keeps_the_event_without_a_building(): void
    {
        $this->building('Главный корпус', 'Главный вход');
        $identity = $this->studentIdentity('Иванов', 'Дмитрий');

        $this->scan($identity, 'Калитка у склада')
            ->assertOk()
            ->assertJsonPath('data.access_point_id', null)
            ->assertJsonPath('data.access_point', 'Калитка у склада');

        $this->getJson('/api/access/muster')
            ->assertOk()
            ->assertJsonPath('data.inside_now', 1)
            ->assertJsonPath('data.buildings.1.building_name', 'Точка прохода не указана')
            ->assertJsonPath('data.buildings.1.people.0.full_name', 'Иванов Дмитрий');
    }

    public function test_muster_lists_people_by_building_and_keeps_empty_buildings(): void
    {
        $main = $this->building('Главный корпус', 'Главный вход', 1);
        $this->building('Учебный корпус 2', 'Вход со двора', 2);

        $student = $this->studentIdentity('Альгашова', 'Мария');
        $teacher = $this->teacherIdentity('Петров', 'Алексей');

        $this->scan($student, 'Главный вход')->assertOk();
        $this->scan($teacher, 'Главный вход')->assertOk();

        $response = $this->getJson('/api/access/muster')->assertOk();

        $response
            ->assertJsonPath('data.inside_now', 2)
            ->assertJsonPath('data.buildings.0.building_name', 'Главный корпус')
            ->assertJsonPath('data.buildings.0.people_count', 2)
            // Список отсортирован по фамилии: на эвакуации по нему перекликаются.
            ->assertJsonPath('data.buildings.0.people.0.full_name', 'Альгашова Мария')
            ->assertJsonPath('data.buildings.0.people.0.entity_label', 'Студент')
            ->assertJsonPath('data.buildings.0.people.0.access_point', 'Главный вход')
            ->assertJsonPath('data.buildings.0.people.1.full_name', 'Петров Алексей')
            ->assertJsonPath('data.buildings.0.people.1.entity_label', 'Преподаватель')
            // Пустой корпус остается в списке: видно, что он проверен, а не потерян.
            ->assertJsonPath('data.buildings.1.building_name', 'Учебный корпус 2')
            ->assertJsonPath('data.buildings.1.people_count', 0)
            ->assertJsonPath('data.buildings.1.people', []);

        $this->assertSame($main['building']->id, $response->json('data.buildings.0.building_id'));
    }

    public function test_person_who_left_disappears_from_the_muster(): void
    {
        $this->building('Главный корпус', 'Главный вход');
        $identity = $this->studentIdentity('Иванов', 'Дмитрий');

        $this->scan($identity, 'Главный вход')->assertOk();
        $this->getJson('/api/access/muster')->assertOk()->assertJsonPath('data.inside_now', 1);

        $this->travel(2)->minutes();
        $this->scan($identity, 'Главный вход')
            ->assertOk()
            ->assertJsonPath('data.direction', AccessEvent::DIRECTION_OUT);

        $this->getJson('/api/access/muster')
            ->assertOk()
            ->assertJsonPath('data.inside_now', 0)
            ->assertJsonPath('data.buildings.0.people_count', 0);
    }

    public function test_yesterday_entry_is_not_on_the_muster(): void
    {
        $context = $this->building('Главный корпус', 'Главный вход');
        $identity = $this->studentIdentity('Иванов', 'Дмитрий');

        AccessEvent::create([
            'digital_identity_id' => $identity->id,
            'access_point_id' => $context['point']->id,
            'entity_type' => $identity->entity_type,
            'entity_id' => $identity->entity_id,
            'direction' => AccessEvent::DIRECTION_IN,
            'event_time' => CarbonImmutable::parse('2026-09-09 09:05:00'),
            'result' => AccessEvent::RESULT_ALLOWED,
        ]);

        $this->getJson('/api/access/muster')
            ->assertOk()
            ->assertJsonPath('data.inside_now', 0);
    }

    public function test_muster_and_summary_agree_on_the_same_number(): void
    {
        $this->building('Главный корпус', 'Главный вход');
        $this->scan($this->studentIdentity('Иванов', 'Дмитрий'), 'Главный вход')->assertOk();
        $this->scan($this->teacherIdentity('Петров', 'Алексей'), 'Неизвестная точка')->assertOk();

        $muster = $this->getJson('/api/access/muster')->assertOk()->json('data.inside_now');
        $summary = $this->getJson('/api/access/reports/summary')->assertOk()->json('data.inside_now');
        $dashboard = $this->getJson('/api/dashboard/analytics/executive')->assertOk()->json('data.kpi.access.inside_now');

        $this->assertSame(2, $muster);
        $this->assertSame($muster, $summary);
        $this->assertSame($muster, $dashboard);
    }

    public function test_buildings_and_points_are_managed_through_the_reference(): void
    {
        $building = $this->postJson('/api/access/buildings', [
            'name' => 'Главный корпус',
            'code' => 'MAIN',
            'address' => 'ул. Ленина, 1',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/access/buildings', ['name' => 'Главный корпус'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->postJson('/api/access/points', [
            'building_id' => $building,
            'name' => 'Главный вход',
            'code' => 'MAIN-1',
        ])->assertCreated()->assertJsonPath('data.building_name', 'Главный корпус');

        $this->postJson('/api/access/points', ['building_id' => $building, 'name' => 'Главный вход'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        // Корпус с точками не удаляется: события ссылаются на них.
        $this->deleteJson("/api/access/buildings/{$building}")->assertStatus(422);

        $this->getJson('/api/access/buildings')
            ->assertOk()
            ->assertJsonPath('data.0.access_points_count', 1);
    }

    /** @return array{building: Building, point: AccessPoint} */
    private function building(string $name, string $pointName, int $sortOrder = 0): array
    {
        $building = Building::create(['name' => $name, 'sort_order' => $sortOrder]);
        $point = AccessPoint::create(['building_id' => $building->id, 'name' => $pointName]);

        return ['building' => $building, 'point' => $point];
    }

    private function scan(DigitalIdentity $identity, string $accessPoint)
    {
        return $this->postJson('/api/access/scan', [
            'token' => app(QrSvgService::class)->dynamicPayload($identity)['payload'],
            'access_point' => $accessPoint,
        ]);
    }

    private function studentIdentity(string $lastName, string $firstName): DigitalIdentity
    {
        $group = Group::firstOrCreate(
            ['name' => 'ИСП-101'],
            ['specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026],
        );
        $student = Student::create([
            'group_id' => $group->id,
            'last_name' => $lastName,
            'first_name' => $firstName,
            'status' => 'active',
        ]);

        return $this->identity(DigitalIdentity::ENTITY_STUDENT, $student->id);
    }

    private function teacherIdentity(string $lastName, string $firstName): DigitalIdentity
    {
        $teacher = Teacher::create([
            'last_name' => $lastName,
            'first_name' => $firstName,
            'is_active' => true,
        ]);

        return $this->identity(DigitalIdentity::ENTITY_TEACHER, $teacher->id);
    }

    private function identity(string $entityType, int $entityId): DigitalIdentity
    {
        return DigitalIdentity::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'token' => (string) Str::uuid(),
            'status' => DigitalIdentity::STATUS_ACTIVE,
            'issued_at' => now(),
        ]);
    }
}
