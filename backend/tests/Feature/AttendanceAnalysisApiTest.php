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
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10 12:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_it_analyzes_teacher_attendance_against_schedule(): void
    {
        $context = $this->createScheduleContext();
        $teacher = $context['teacher'];
        $this->createLesson($context, '09:00', '10:30');
        $identity = DigitalIdentity::create([
            'entity_type' => 'teacher',
            'entity_id' => $teacher->id,
            'token' => 'teacher-token',
            'status' => 'active',
            'issued_at' => now(),
        ]);
        AccessEvent::create([
            'digital_identity_id' => $identity->id,
            'entity_type' => 'teacher',
            'entity_id' => $teacher->id,
            'direction' => AccessEvent::DIRECTION_IN,
            'event_time' => '2026-09-10 08:40:00',
            'result' => AccessEvent::RESULT_ALLOWED,
        ]);

        $this->getJson('/api/attendance/teachers/today')
            ->assertOk()
            ->assertJsonPath('data.0.full_name', 'Петров Алексей')
            ->assertJsonPath('data.0.status', 'early')
            ->assertJsonPath('data.0.first_lesson.starts_at', '09:00')
            ->assertJsonPath('data.0.inside_now', true);
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
        $identity = DigitalIdentity::create([
            'entity_type' => 'student',
            'entity_id' => $lateStudent->id,
            'token' => 'student-token',
            'status' => 'active',
            'issued_at' => now(),
        ]);
        AccessEvent::create([
            'digital_identity_id' => $identity->id,
            'entity_type' => 'student',
            'entity_id' => $lateStudent->id,
            'direction' => AccessEvent::DIRECTION_IN,
            'event_time' => '2026-09-10 09:17:00',
            'result' => AccessEvent::RESULT_ALLOWED,
        ]);

        $response = $this->getJson('/api/attendance/students/today')
            ->assertOk()
            ->assertJsonPath('summary.total', 2);

        $rows = collect($response->json('data'))->keyBy('full_name');
        $this->assertSame('late', $rows->get('Иванов Дмитрий')['status']);
        $this->assertSame(17, $rows->get('Иванов Дмитрий')['late_minutes']);
        $this->assertSame('not_entered', $rows->get('Сидорова Анна')['status']);
        $this->assertSame($missingStudent->id, $rows->get('Сидорова Анна')['entity_id']);
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
}
