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
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceAnalysisApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $now = CarbonImmutable::parse('2026-09-10 12:00:00');
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

    public function test_it_analyzes_teacher_attendance_against_schedule(): void
    {
        $context = $this->createScheduleContext();
        $teacher = $context['teacher'];
        $this->createLesson($context, '09:00', '10:30');
        $this->addAccessEvent('teacher', $teacher->id, '08:40');

        $this->getJson('/api/attendance/teachers/today?date_from=2026-09-10&date_to=2026-09-10')
            ->assertOk()
            ->assertJsonPath('data.0.full_name', 'Петров Алексей')
            ->assertJsonPath('data.0.status', 'early')
            ->assertJsonPath('data.0.first_lesson.starts_at', '09:00')
            ->assertJsonPath('data.0.inside_now', true)
            ->assertJsonPath('summary.on_time', 1);
    }

    public function test_it_analyzes_student_lateness_and_absence(): void
    {
        $context = $this->createScheduleContext();
        $this->createLesson($context, '09:00', '10:30');
        $lateStudent = Student::create([
            'group_id' => $context['group']->id,
            'last_name' => 'Иванов',
            'first_name' => 'Дмитрий',
            'status' => 'active',
        ]);
        $missingStudent = Student::create([
            'group_id' => $context['group']->id,
            'last_name' => 'Сидорова',
            'first_name' => 'Анна',
            'status' => 'active',
        ]);
        $this->addAccessEvent('student', $lateStudent->id, '09:17');

        $response = $this->getJson('/api/attendance/students/today?date_from=2026-09-10&date_to=2026-09-10')
            ->assertOk()
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.late', 1)
            ->assertJsonPath('summary.absent', 1);

        $rows = collect($response->json('data'))->keyBy('full_name');
        $this->assertSame('late', $rows->get('Иванов Дмитрий')['status']);
        $this->assertSame(17, $rows->get('Иванов Дмитрий')['late_minutes']);
        $this->assertSame('2026-09-10T09:17:00', $rows->get('Иванов Дмитрий')['first_entry']);
        $this->assertSame('not_entered', $rows->get('Сидорова Анна')['status']);
        $this->assertSame($missingStudent->id, $rows->get('Сидорова Анна')['entity_id']);
    }

    public function test_it_reports_on_time_no_schedule_and_no_passes(): void
    {
        $context = $this->createScheduleContext();
        $this->createLesson($context, '09:00', '10:30');
        $onTime = Student::create(['group_id' => $context['group']->id, 'last_name' => 'Орлов', 'first_name' => 'Илья', 'status' => 'active']);
        $noPass = Student::create(['group_id' => $context['group']->id, 'last_name' => 'Котова', 'first_name' => 'Мария', 'status' => 'active']);
        $emptyGroup = Group::create(['name' => 'ВК-101', 'specialty' => 'Вокал', 'course' => 1, 'year_start' => 2026]);
        $noSchedule = Student::create(['group_id' => $emptyGroup->id, 'last_name' => 'Лебедев', 'first_name' => 'Семен', 'status' => 'active']);
        $this->addAccessEvent('student', $onTime->id, '09:00');
        $this->addAccessEvent('student', $noSchedule->id, '08:20');

        $rows = collect($this->getJson('/api/attendance/students/today?date_from=2026-09-10&date_to=2026-09-10')->assertOk()->json('data'))->keyBy('full_name');

        $this->assertSame('present', $rows->get('Орлов Илья')['status']);
        $this->assertSame('not_entered', $rows->get('Котова Мария')['status']);
        $this->assertSame('entered', $rows->get('Лебедев Семен')['status']);
        $this->assertSame($noPass->id, $rows->get('Котова Мария')['entity_id']);
    }

    public function test_it_filters_attendance_api(): void
    {
        $context = $this->createScheduleContext();
        $this->createLesson($context, '09:00', '10:30');
        $student = Student::create(['group_id' => $context['group']->id, 'last_name' => 'Иванов', 'first_name' => 'Дмитрий', 'status' => 'active']);
        $otherGroup = Group::create(['name' => 'ДХ-101', 'specialty' => 'Дизайн', 'course' => 1, 'year_start' => 2026]);
        Student::create(['group_id' => $otherGroup->id, 'last_name' => 'Петрова', 'first_name' => 'Алина', 'status' => 'active']);
        $this->addAccessEvent('student', $student->id, '09:15');

        $this->getJson("/api/attendance/students/today?date_from=2026-09-10&date_to=2026-09-10&status=late&group_id={$context['group']->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Иванов Дмитрий');

        $this->getJson("/api/attendance/teachers/today?date_from=2026-09-10&date_to=2026-09-10&teacher_id={$context['teacher']->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.entity_id', $context['teacher']->id);
    }

    public function test_executive_dashboard_contains_attendance_aggregates(): void
    {
        $context = $this->createScheduleContext();
        $teacher = $context['teacher'];
        $this->createLesson($context, '09:00', '10:30');
        $student = Student::create(['group_id' => $context['group']->id, 'last_name' => 'Иванов', 'first_name' => 'Дмитрий', 'status' => 'active']);
        Student::create(['group_id' => $context['group']->id, 'last_name' => 'Сидорова', 'first_name' => 'Анна', 'status' => 'active']);
        $this->addAccessEvent('teacher', $teacher->id, '08:50');
        $this->addAccessEvent('student', $student->id, '09:20');

        $this->getJson('/api/dashboard/analytics/executive')
            ->assertOk()
            ->assertJsonPath('data.kpi.attendance.teachers.on_time', 1)
            ->assertJsonPath('data.kpi.attendance.students.late', 1)
            ->assertJsonPath('data.kpi.attendance.students.absent', 1)
            ->assertJsonPath('data.attention.1.title', 'Преподаватели опоздали');
    }


    public function test_history_calculates_single_and_multiple_sessions(): void
    {
        $context = $this->createScheduleContext();
        $this->createLesson($context, '09:00', '11:00');
        $student = Student::create(['group_id' => $context['group']->id, 'last_name' => 'Иванов', 'first_name' => 'Дмитрий', 'status' => 'active']);
        $this->addAccessEventAt('student', $student->id, '2026-09-10 08:50:00', AccessEvent::DIRECTION_IN);
        $this->addAccessEventAt('student', $student->id, '2026-09-10 10:00:00', AccessEvent::DIRECTION_OUT);
        $this->addAccessEventAt('student', $student->id, '2026-09-10 10:10:00', AccessEvent::DIRECTION_IN);
        $this->addAccessEventAt('student', $student->id, '2026-09-10 11:20:00', AccessEvent::DIRECTION_OUT);

        $this->getJson('/api/attendance/history?type=student&date_from=2026-09-10&date_to=2026-09-10')
            ->assertOk()
            ->assertJsonPath('data.0.full_name', 'Иванов Дмитрий')
            ->assertJsonPath('data.0.entries_count', 2)
            ->assertJsonPath('data.0.exits_count', 2)
            ->assertJsonPath('data.0.minutes_inside', 140)
            ->assertJsonPath('data.0.has_open_session', false);
    }

    public function test_history_tracks_open_session_unmatched_exit_and_midnight_exit(): void
    {
        $context = $this->createScheduleContext();
        $this->createLesson($context, '22:00', '23:30');
        $student = Student::create(['group_id' => $context['group']->id, 'last_name' => 'Ночная', 'first_name' => 'Смена', 'status' => 'active']);
        $open = Student::create(['group_id' => $context['group']->id, 'last_name' => 'Открытый', 'first_name' => 'Вход', 'status' => 'active']);
        $outOnly = Student::create(['group_id' => $context['group']->id, 'last_name' => 'Лишний', 'first_name' => 'Выход', 'status' => 'active']);
        $this->addAccessEventAt('student', $student->id, '2026-09-10 23:10:00', AccessEvent::DIRECTION_IN);
        $this->addAccessEventAt('student', $student->id, '2026-09-11 00:40:00', AccessEvent::DIRECTION_OUT);
        $this->addAccessEventAt('student', $open->id, '2026-09-10 08:30:00', AccessEvent::DIRECTION_IN);
        $this->addAccessEventAt('student', $outOnly->id, '2026-09-10 12:00:00', AccessEvent::DIRECTION_OUT);

        $days = collect($this->getJson("/api/attendance/person/student/{$student->id}/days?date_from=2026-09-10&date_to=2026-09-10")->assertOk()->json('data'));
        $this->assertSame(90, $days->first()['minutes_inside']);
        $this->assertSame('2026-09-11T00:40:00.000000Z', $days->first()['last_exit']);

        $rows = collect($this->getJson('/api/attendance/history?type=student&date_from=2026-09-10&date_to=2026-09-10')->assertOk()->json('data'))->keyBy('full_name');
        $this->assertTrue($rows->get('Открытый Вход')['has_open_session']);

        $outOnlyDays = collect($this->getJson("/api/attendance/person/student/{$outOnly->id}/days?date_from=2026-09-10&date_to=2026-09-10")->assertOk()->json('data'));
        $this->assertSame(1, $outOnlyDays->first()['unmatched_exits_count']);
        $this->assertSame(0, $outOnlyDays->first()['minutes_inside']);
    }

    public function test_history_tracks_late_early_leave_absence_and_no_schedule(): void
    {
        $context = $this->createScheduleContext();
        $this->createLesson($context, '09:00', '12:00');
        $late = Student::create(['group_id' => $context['group']->id, 'last_name' => 'Поздний', 'first_name' => 'Студент', 'status' => 'active']);
        $earlyLeave = Student::create(['group_id' => $context['group']->id, 'last_name' => 'Ранний', 'first_name' => 'Уход', 'status' => 'active']);
        Student::create(['group_id' => $context['group']->id, 'last_name' => 'Нет', 'first_name' => 'Входа', 'status' => 'active']);
        $emptyGroup = Group::create(['name' => 'П-201', 'specialty' => 'Пение', 'course' => 2, 'year_start' => 2025]);
        Student::create(['group_id' => $emptyGroup->id, 'last_name' => 'Без', 'first_name' => 'Расписания', 'status' => 'active']);
        $this->addAccessEventAt('student', $late->id, '2026-09-10 09:20:00', AccessEvent::DIRECTION_IN);
        $this->addAccessEventAt('student', $late->id, '2026-09-10 12:05:00', AccessEvent::DIRECTION_OUT);
        $this->addAccessEventAt('student', $earlyLeave->id, '2026-09-10 08:50:00', AccessEvent::DIRECTION_IN);
        $this->addAccessEventAt('student', $earlyLeave->id, '2026-09-10 11:30:00', AccessEvent::DIRECTION_OUT);

        $rows = collect($this->getJson('/api/attendance/history?type=student&date_from=2026-09-10&date_to=2026-09-10')->assertOk()->json('data'))->keyBy('full_name');

        $this->assertSame(20, $rows->get('Поздний Студент')['late_minutes_total']);
        $this->assertSame(30, $rows->get('Ранний Уход')['early_leave_minutes_total']);
        $this->assertSame(1, $rows->get('Нет Входа')['absent_days']);
        $this->assertSame(1, $rows->get('Без Расписания')['days_without_schedule']);
    }

    public function test_history_filters_and_exports_csv(): void
    {
        $context = $this->createScheduleContext();
        $this->createLesson($context, '09:00', '10:30');
        $student = Student::create(['group_id' => $context['group']->id, 'last_name' => 'Иванов', 'first_name' => 'Дмитрий', 'status' => 'active']);
        $this->addAccessEventAt('student', $student->id, '2026-09-10 09:15:00', AccessEvent::DIRECTION_IN);
        $this->addAccessEventAt('student', $student->id, '2026-09-10 10:40:00', AccessEvent::DIRECTION_OUT);

        $this->getJson('/api/attendance/history?type=student&status=late&date_from=2026-09-10&date_to=2026-09-10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Иванов Дмитрий');

        $response = $this->get('/api/attendance/history?type=student&export=csv&date_from=2026-09-10&date_to=2026-09-10')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('ФИО', $csv);
        $this->assertStringContainsString('Иванов Дмитрий', $csv);
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


    private function addAccessEventAt(string $type, int $id, string $dateTime, string $direction = AccessEvent::DIRECTION_IN): void
    {
        $identity = DigitalIdentity::firstOrCreate(
            ['entity_type' => $type, 'entity_id' => $id],
            ['token' => $type.'-'.$id, 'status' => 'active', 'issued_at' => now()],
        );

        AccessEvent::create([
            'digital_identity_id' => $identity->id,
            'entity_type' => $type,
            'entity_id' => $id,
            'direction' => $direction,
            'event_time' => $dateTime,
            'result' => AccessEvent::RESULT_ALLOWED,
        ]);
    }

    private function addAccessEvent(string $type, int $id, string $time, string $direction = AccessEvent::DIRECTION_IN): void
    {
        $identity = DigitalIdentity::firstOrCreate(
            ['entity_type' => $type, 'entity_id' => $id],
            ['token' => $type.'-'.$id, 'status' => 'active', 'issued_at' => now()],
        );

        AccessEvent::create([
            'digital_identity_id' => $identity->id,
            'entity_type' => $type,
            'entity_id' => $id,
            'direction' => $direction,
            'event_time' => '2026-09-10 '.$time.':00',
            'result' => AccessEvent::RESULT_ALLOWED,
        ]);
    }
}
