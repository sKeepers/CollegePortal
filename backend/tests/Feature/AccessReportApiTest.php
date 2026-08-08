<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\DigitalIdentity;
use App\Models\Group;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccessReportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withApiAuth();
    }

    public function test_it_returns_access_summary(): void
    {
        $studentIdentity = $this->createStudentIdentity();
        $teacherIdentity = $this->createTeacherIdentity();

        AccessEvent::create([
            'digital_identity_id' => $studentIdentity->id,
            'entity_type' => 'student',
            'entity_id' => $studentIdentity->entity_id,
            'direction' => AccessEvent::DIRECTION_IN,
            'event_time' => now(),
            'result' => AccessEvent::RESULT_ALLOWED,
        ]);
        AccessEvent::create([
            'digital_identity_id' => $teacherIdentity->id,
            'entity_type' => 'teacher',
            'entity_id' => $teacherIdentity->entity_id,
            'direction' => AccessEvent::DIRECTION_OUT,
            'event_time' => now(),
            'result' => AccessEvent::RESULT_ALLOWED,
        ]);
        AccessEvent::create([
            'digital_identity_id' => null,
            'direction' => AccessEvent::DIRECTION_IN,
            'event_time' => now(),
            'result' => AccessEvent::RESULT_DENIED,
            'reason' => 'Пропуск не найден.',
        ]);

        $this->getJson('/api/access/reports/summary')
            ->assertOk()
            ->assertJsonPath('data.today_events', 3)
            ->assertJsonPath('data.entries', 1)
            ->assertJsonPath('data.exits', 1)
            ->assertJsonPath('data.denied', 1)
            ->assertJsonPath('data.inside_now', 1);
    }

    public function test_it_filters_access_events_by_owner_type_result_and_search(): void
    {
        $studentIdentity = $this->createStudentIdentity();
        $teacherIdentity = $this->createTeacherIdentity();

        AccessEvent::create([
            'digital_identity_id' => $studentIdentity->id,
            'entity_type' => 'student',
            'entity_id' => $studentIdentity->entity_id,
            'direction' => AccessEvent::DIRECTION_IN,
            'event_time' => now(),
            'result' => AccessEvent::RESULT_ALLOWED,
        ]);
        AccessEvent::create([
            'digital_identity_id' => $teacherIdentity->id,
            'entity_type' => 'teacher',
            'entity_id' => $teacherIdentity->entity_id,
            'direction' => AccessEvent::DIRECTION_IN,
            'event_time' => now(),
            'result' => AccessEvent::RESULT_DENIED,
            'reason' => 'Пропуск отозван.',
        ]);

        $this->getJson('/api/access/reports/events?entity_type=student&result=allowed&search=Иванов')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.owner.last_name', 'Иванов');
    }

    public function test_it_exports_access_events_to_csv(): void
    {
        $identity = $this->createStudentIdentity();
        AccessEvent::create([
            'digital_identity_id' => $identity->id,
            'entity_type' => 'student',
            'entity_id' => $identity->entity_id,
            'direction' => AccessEvent::DIRECTION_IN,
            'event_time' => now(),
            'result' => AccessEvent::RESULT_ALLOWED,
        ]);

        $response = $this->get('/api/access/reports/events?export=csv');

        $response->assertOk();
        // Дата и время разведены по столбцам, чтобы выгрузку можно было свести
        // сводной таблицей по дням.
        $this->assertStringContainsString('Дата;Время;ФИО', $response->streamedContent());
        $this->assertStringContainsString('Иванов', $response->streamedContent());
    }

    private function createStudentIdentity(): DigitalIdentity
    {
        $group = Group::create([
            'name' => 'ИСП-101',
            'specialty' => 'Инструментальное исполнительство',
            'course' => 1,
            'year_start' => 2026,
        ]);
        $student = Student::create([
            'group_id' => $group->id,
            'last_name' => 'Иванов',
            'first_name' => 'Дмитрий',
            'status' => 'active',
        ]);

        return DigitalIdentity::create([
            'entity_type' => 'student',
            'entity_id' => $student->id,
            'token' => (string) Str::uuid(),
            'status' => 'active',
            'issued_at' => now(),
        ]);
    }

    private function createTeacherIdentity(): DigitalIdentity
    {
        $teacher = Teacher::create([
            'last_name' => 'Смирнова',
            'first_name' => 'Елена',
            'department' => 'Музыкальное отделение',
        ]);

        return DigitalIdentity::create([
            'entity_type' => 'teacher',
            'entity_id' => $teacher->id,
            'token' => (string) Str::uuid(),
            'status' => 'active',
            'issued_at' => now(),
        ]);
    }
}
