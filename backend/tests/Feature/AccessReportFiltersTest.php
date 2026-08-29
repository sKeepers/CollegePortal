<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\AccessPoint;
use App\Models\Building;
use App\Support\Time\CollegeTime;
use App\Models\Classroom;
use App\Models\DigitalIdentity;
use App\Models\Group;
use App\Models\ScheduleLesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Отчет по проходам за период: фильтр по сотрудникам, «только опоздавшие» и
 * выгрузка, по которой можно работать, а не только смотреть.
 */
class AccessReportFiltersTest extends TestCase
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

    public function test_only_late_keeps_the_late_student_and_drops_the_punctual_one(): void
    {
        $context = $this->context();
        $this->lesson($context, '09:00', '10:30');

        $late = Student::create(['group_id' => $context['group']->id, 'last_name' => 'Опоздавший', 'first_name' => 'Иван', 'status' => 'active']);
        $onTime = Student::create(['group_id' => $context['group']->id, 'last_name' => 'Вовремя', 'first_name' => 'Петр', 'status' => 'active']);

        // Опоздание на 15 минут и вход за 20 минут до пары.
        $this->event($this->identity(DigitalIdentity::ENTITY_STUDENT, $late->id), '2026-09-10 09:15:00');
        $this->event($this->identity(DigitalIdentity::ENTITY_STUDENT, $onTime->id), '2026-09-10 08:40:00');

        $this->getJson('/api/access/reports/events?date_from=2026-09-10&date_to=2026-09-10&entity_type=student')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $filtered = $this->getJson('/api/access/reports/events?date_from=2026-09-10&date_to=2026-09-10&entity_type=student&only_late=1')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame('Опоздавший', $filtered->json('data.0.owner.last_name'));
    }

    public function test_events_can_be_filtered_down_to_employees(): void
    {
        $context = $this->context();
        $student = Student::create(['group_id' => $context['group']->id, 'last_name' => 'Иванов', 'first_name' => 'Дмитрий', 'status' => 'active']);

        $this->event($this->identity(DigitalIdentity::ENTITY_STUDENT, $student->id), '2026-09-10 09:15:00');
        $this->event($this->identity(DigitalIdentity::ENTITY_TEACHER, $context['teacher']->id), '2026-09-10 08:40:00');

        $this->getJson('/api/access/reports/events?date_from=2026-09-10&date_to=2026-09-10&entity_type=employee')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/access/reports/events?date_from=2026-09-10&date_to=2026-09-10&entity_type=teacher')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_export_names_the_building_and_splits_date_from_time(): void
    {
        $context = $this->context();
        $building = Building::create(['name' => 'Главный корпус']);
        $point = AccessPoint::create(['building_id' => $building->id, 'name' => 'Главный вход']);
        $student = Student::create(['group_id' => $context['group']->id, 'last_name' => 'Иванов', 'first_name' => 'Дмитрий', 'status' => 'active']);

        $identity = $this->identity(DigitalIdentity::ENTITY_STUDENT, $student->id);
        AccessEvent::create([
            'digital_identity_id' => $identity->id,
            'access_point_id' => $point->id,
            'entity_type' => $identity->entity_type,
            'entity_id' => $identity->entity_id,
            'direction' => AccessEvent::DIRECTION_IN,
            // Момент задаётся так, чтобы он **значил** четверть десятого
            // утра по колледжу: портал хранит время в UTC, а на лист
            // выгрузка с 30.08.2026 печатает местный час. Прежняя
            // редакция писала 09:15 UTC, и проверка проходила потому, что
            // приспособление и печать ошибались одинаково.
            'event_time' => CollegeTime::moment('2026-09-10', '09:15'),
            'access_point' => 'Главный вход',
            'result' => AccessEvent::RESULT_ALLOWED,
        ]);

        $response = $this->get('/api/access/reports/events?date_from=2026-09-10&date_to=2026-09-10&export=csv');
        $response->assertOk();

        $csv = $response->streamedContent();

        // fputcsv берет в кавычки поля с пробелами — это и есть корректный CSV.
        $this->assertStringContainsString('Дата;Время;ФИО;Тип;"Группа или подразделение";Корпус;"Точка доступа"', $csv);
        $this->assertStringContainsString('10.09.2026;09:15;"Иванов Дмитрий";Студент;ИСП-101;"Главный корпус";"Главный вход"', $csv);
    }

    private function context(): array
    {
        return [
            'group' => Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]),
            'teacher' => Teacher::create(['last_name' => 'Петров', 'first_name' => 'Алексей', 'is_active' => true]),
            'subject' => Subject::create(['name' => 'Сольфеджио', 'code' => 'SOL-101']),
            'classroom' => Classroom::create(['number' => '201', 'building' => 'Главный']),
        ];
    }

    private function lesson(array $context, string $startsAt, string $endsAt): ScheduleLesson
    {
        return ScheduleLesson::create([
            'group_id' => $context['group']->id,
            'teacher_id' => $context['teacher']->id,
            'subject_id' => $context['subject']->id,
            'classroom_id' => $context['classroom']->id,
            'lesson_date' => '2026-09-10',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'lesson_type' => 'lesson',
            'topic' => 'Тестовая пара',
        ]);
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

    private function event(DigitalIdentity $identity, string $at): AccessEvent
    {
        return AccessEvent::create([
            'digital_identity_id' => $identity->id,
            'entity_type' => $identity->entity_type,
            'entity_id' => $identity->entity_id,
            'direction' => AccessEvent::DIRECTION_IN,
            'event_time' => CollegeTime::moment(substr($at, 0, 10), substr($at, 11)),
            'result' => AccessEvent::RESULT_ALLOWED,
        ]);
    }
}
