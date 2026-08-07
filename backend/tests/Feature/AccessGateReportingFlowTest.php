<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\Classroom;
use App\Models\DigitalIdentity;
use App\Models\Group;
use App\Models\ScheduleLesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Services\QrSvgService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Проходная, отчеты и виджеты проверяются по отдельности, но шов между ними —
 * нет. Здесь проход выполняется именно через сканирование, а дальше проверяется,
 * что его увидели сводка отчетов, KPI рабочего стола и разбор посещаемости.
 */
class AccessGateReportingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Занятие в 09:00, проход в 09:15 — опоздание ровно на 15 минут.
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

    public function test_scan_reaches_reports_dashboard_and_attendance(): void
    {
        $context = $this->createScheduleContext();
        $student = Student::create([
            'group_id' => $context['group']->id,
            'last_name' => 'Иванов',
            'first_name' => 'Дмитрий',
            'status' => 'active',
        ]);
        $identity = $this->createIdentity(DigitalIdentity::ENTITY_STUDENT, $student->id);
        $this->createLesson($context, '09:00', '10:30');

        $this->scan($identity)
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_ALLOWED)
            ->assertJsonPath('data.direction', AccessEvent::DIRECTION_IN);

        $this->getJson('/api/access/reports/summary')
            ->assertOk()
            ->assertJsonPath('data.today_events', 1)
            ->assertJsonPath('data.entries', 1)
            ->assertJsonPath('data.exits', 0)
            ->assertJsonPath('data.denied', 0)
            ->assertJsonPath('data.inside_now', 1);

        $this->getJson('/api/dashboard/analytics/executive')
            ->assertOk()
            ->assertJsonPath('data.kpi.access.entries_today', 1)
            ->assertJsonPath('data.kpi.access.exits_today', 0)
            ->assertJsonPath('data.kpi.access.denied_today', 0)
            ->assertJsonPath('data.kpi.access.inside_now', 1);

        $this->getJson('/api/attendance/students/today?date_from=2026-09-10&date_to=2026-09-10')
            ->assertOk()
            ->assertJsonPath('data.0.full_name', 'Иванов Дмитрий')
            ->assertJsonPath('data.0.status', 'late')
            ->assertJsonPath('data.0.late_minutes', 15)
            ->assertJsonPath('data.0.inside_now', true)
            ->assertJsonPath('data.0.first_entry', '2026-09-10T09:15:00');

        $this->getJson('/api/access/reports/events')
            ->assertOk()
            ->assertJsonPath('data.0.direction', AccessEvent::DIRECTION_IN)
            ->assertJsonPath('data.0.owner.last_name', 'Иванов');

        // Выход считается следующим сканированием после окна подавления дублей.
        $this->travel(2)->minutes();
        $this->scan($identity)
            ->assertOk()
            ->assertJsonPath('data.direction', AccessEvent::DIRECTION_OUT);

        $this->getJson('/api/access/reports/summary')
            ->assertOk()
            ->assertJsonPath('data.entries', 1)
            ->assertJsonPath('data.exits', 1)
            ->assertJsonPath('data.inside_now', 0);

        $this->getJson('/api/dashboard/analytics/executive')
            ->assertOk()
            ->assertJsonPath('data.kpi.access.entries_today', 1)
            ->assertJsonPath('data.kpi.access.exits_today', 1)
            ->assertJsonPath('data.kpi.access.inside_now', 0);

        $this->getJson('/api/attendance/students/today?date_from=2026-09-10&date_to=2026-09-10')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'late')
            ->assertJsonPath('data.0.inside_now', false);
    }

    public function test_denied_scan_is_visible_in_reports_and_dashboard(): void
    {
        $this->postJson('/api/access/scan', ['token' => 'CP2:UNKNOWN:0123456789abcdef'])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_DENIED);

        $this->getJson('/api/access/reports/summary')
            ->assertOk()
            ->assertJsonPath('data.today_events', 1)
            ->assertJsonPath('data.entries', 0)
            ->assertJsonPath('data.denied', 1)
            ->assertJsonPath('data.inside_now', 0);

        $this->getJson('/api/dashboard/analytics/executive')
            ->assertOk()
            ->assertJsonPath('data.kpi.access.denied_today', 1)
            ->assertJsonPath('data.kpi.access.entries_today', 0)
            ->assertJsonPath('data.kpi.access.inside_now', 0);
    }

    public function test_yesterday_entry_without_exit_does_not_count_as_inside_today(): void
    {
        $context = $this->createScheduleContext();
        $student = Student::create([
            'group_id' => $context['group']->id,
            'last_name' => 'Иванов',
            'first_name' => 'Дмитрий',
            'status' => 'active',
        ]);
        $identity = $this->createIdentity(DigitalIdentity::ENTITY_STUDENT, $student->id);

        // Человек вошел вчера и не отсканировал выход.
        AccessEvent::create([
            'digital_identity_id' => $identity->id,
            'entity_type' => $identity->entity_type,
            'entity_id' => $identity->entity_id,
            'direction' => AccessEvent::DIRECTION_IN,
            'event_time' => CarbonImmutable::parse('2026-09-09 09:05:00'),
            'result' => AccessEvent::RESULT_ALLOWED,
        ]);

        $this->getJson('/api/access/reports/summary')
            ->assertOk()
            ->assertJsonPath('data.today_events', 0)
            ->assertJsonPath('data.inside_now', 0);

        $this->getJson('/api/dashboard/analytics/executive')
            ->assertOk()
            ->assertJsonPath('data.kpi.access.inside_now', 0)
            ->assertJsonPath('data.kpi.access.entries_today', 0);
    }

    public function test_teacher_entry_before_lesson_is_counted_as_on_time(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10 08:40:00'));
        Carbon::setTestNow(Carbon::parse('2026-09-10 08:40:00'));

        $context = $this->createScheduleContext();
        $this->createLesson($context, '09:00', '10:30');
        $identity = $this->createIdentity(DigitalIdentity::ENTITY_TEACHER, $context['teacher']->id);

        $this->scan($identity)->assertOk()->assertJsonPath('data.result', AccessEvent::RESULT_ALLOWED);

        $this->getJson('/api/attendance/teachers/today?date_from=2026-09-10&date_to=2026-09-10')
            ->assertOk()
            ->assertJsonPath('data.0.full_name', 'Петров Алексей')
            ->assertJsonPath('data.0.status', 'early')
            ->assertJsonPath('data.0.inside_now', true)
            ->assertJsonPath('summary.on_time', 1);

        $this->getJson('/api/dashboard/analytics/executive')
            ->assertOk()
            ->assertJsonPath('data.kpi.access.entries_today', 1);
    }

    private function scan(DigitalIdentity $identity)
    {
        return $this->postJson('/api/access/scan', [
            'token' => app(QrSvgService::class)->dynamicPayload($identity)['payload'],
            'access_point' => 'Главный вход',
            'device_name' => 'USB QR Scanner',
        ]);
    }

    private function createIdentity(string $entityType, int $entityId): DigitalIdentity
    {
        return DigitalIdentity::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'token' => (string) Str::uuid(),
            'status' => DigitalIdentity::STATUS_ACTIVE,
            'issued_at' => now(),
        ]);
    }

    private function createScheduleContext(): array
    {
        return [
            'group' => Group::create([
                'name' => 'ИСП-101',
                'specialty' => 'Инструментальное исполнительство',
                'course' => 1,
                'year_start' => 2026,
            ]),
            'teacher' => Teacher::create([
                'last_name' => 'Петров',
                'first_name' => 'Алексей',
                'is_active' => true,
            ]),
            'subject' => Subject::create([
                'name' => 'Сольфеджио',
                'code' => 'SOL-101',
            ]),
            'classroom' => Classroom::create([
                'number' => '201',
                'building' => 'Главный',
            ]),
        ];
    }

    private function createLesson(array $context, string $startsAt, string $endsAt, string $date = '2026-09-10'): ScheduleLesson
    {
        return ScheduleLesson::create([
            'group_id' => $context['group']->id,
            'teacher_id' => $context['teacher']->id,
            'subject_id' => $context['subject']->id,
            'classroom_id' => $context['classroom']->id,
            'lesson_date' => $date,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'lesson_type' => 'lesson',
            'topic' => 'Тестовая пара',
        ]);
    }
}
