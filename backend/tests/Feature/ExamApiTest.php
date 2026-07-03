<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Exam;
use App\Models\Group;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ExamApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withApiAuth();
    }

    public function test_it_creates_lists_updates_and_deletes_exam(): void
    {
        [$group, $subject, $teacher, $classroom] = $this->baseEntities();

        $response = $this->postJson('/api/exams', [
            'academic_year' => '2026/2027',
            'semester' => 2,
            'group_id' => $group->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'exam_date' => '2027-06-10',
            'starts_at' => '09:00',
            'ends_at' => '10:30',
            'exam_type' => 'exam',
            'status' => 'scheduled',
            'topic' => 'Итоговый экзамен',
        ]);

        $response->assertCreated()->assertJsonPath('data.subject.name', 'Сольфеджио')->assertJsonPath('data.group.name', 'ИСП-101');
        $examId = $response->json('data.id');

        $this->getJson('/api/exams?academic_year=2026/2027&group_id='.$group->id)
            ->assertOk()
            ->assertJsonPath('data.0.exam_type', 'exam');

        $this->patchJson("/api/exams/{$examId}", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->deleteJson("/api/exams/{$examId}")->assertNoContent();
        $this->assertDatabaseMissing('exams', ['id' => $examId]);
    }

    public function test_it_adds_updates_and_removes_exam_result(): void
    {
        [$group, $subject, $teacher, $classroom] = $this->baseEntities();
        $student = Student::create(['group_id' => $group->id, 'last_name' => 'Иванов', 'first_name' => 'Иван', 'middle_name' => 'Петрович', 'status' => 'active']);
        $exam = $this->createExam($group, $subject, $teacher, $classroom);

        $response = $this->postJson("/api/exams/{$exam->id}/results", [
            'student_id' => $student->id,
            'result' => '5',
            'score' => 96,
            'status' => 'passed',
            'comment' => 'Отличная работа',
        ]);

        $response->assertCreated()->assertJsonPath('data.student.last_name', 'Иванов')->assertJsonPath('data.result', '5');
        $resultId = $response->json('data.id');

        $this->postJson("/api/exams/{$exam->id}/results", ['student_id' => $student->id, 'result' => '4', 'score' => 82, 'status' => 'passed'])
            ->assertOk()
            ->assertJsonPath('data.result', '4');

        $this->deleteJson("/api/exam-results/{$resultId}")->assertNoContent();
        $this->assertDatabaseMissing('exam_results', ['id' => $resultId]);
    }

    public function test_it_exports_and_imports_exams_csv(): void
    {
        [$group, $subject, $teacher, $classroom] = $this->baseEntities();
        $student = Student::create(['group_id' => $group->id, 'last_name' => 'Иванов', 'first_name' => 'Иван', 'status' => 'active']);
        $exam = $this->createExam($group, $subject, $teacher, $classroom);
        $exam->results()->create(['student_id' => $student->id, 'result' => '5', 'score' => 95, 'status' => 'passed']);

        $export = $this->get('/api/exams/export');
        $export->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Сольфеджио', $export->streamedContent());

        $csv = implode("\n", [
            'id;academic_year;semester;group_id;group_name;subject_id;subject_code;subject_name;teacher_id;teacher;classroom_id;classroom;exam_date;starts_at;ends_at;exam_type;status;topic;student_id;student;result;score;result_status;comment',
            ";2027/2028;1;{$group->id};;{$subject->id};;;{$teacher->id};;{$classroom->id};;2027-12-20;11:00;12:30;credit;scheduled;Зачет;{$student->id};;зачет;88;passed;Принято",
        ]);
        $response = $this->post('/api/exams/import', [
            'file' => UploadedFile::fake()->createWithContent('exams.csv', $csv),
        ]);

        $response->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.resultsCreated', 1);
        $this->assertDatabaseHas('exams', ['academic_year' => '2027/2028', 'exam_type' => 'credit']);
        $this->assertDatabaseHas('exam_results', ['student_id' => $student->id, 'result' => 'зачет', 'score' => 88]);
    }

    private function baseEntities(): array
    {
        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]);
        $subject = Subject::create(['name' => 'Сольфеджио', 'code' => 'OP.01']);
        $teacher = Teacher::create(['last_name' => 'Смирнова', 'first_name' => 'Елена', 'middle_name' => 'Викторовна', 'is_active' => true]);
        $classroom = Classroom::create(['number' => '201', 'building' => 'Главный корпус', 'floor' => 2, 'capacity' => 24, 'type' => 'Учебная']);

        return [$group, $subject, $teacher, $classroom];
    }

    private function createExam(Group $group, Subject $subject, Teacher $teacher, Classroom $classroom): Exam
    {
        return Exam::create([
            'academic_year' => '2026/2027',
            'semester' => 2,
            'group_id' => $group->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'exam_date' => '2027-06-10',
            'starts_at' => '09:00',
            'ends_at' => '10:30',
            'exam_type' => 'exam',
            'status' => 'scheduled',
        ]);
    }
}
