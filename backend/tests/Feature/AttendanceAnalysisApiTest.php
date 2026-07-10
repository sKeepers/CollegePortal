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
        $this->withApiAuth();
        $now = CarbonImmutable::parse('2026-09-10 12:00:00');
        CarbonImmutable::setTestNow($now);
        Carbon::setTestNow($now);
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

    private function createLesson(array $context, string $startsAt, string $endsAt): ScheduleLesson
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
