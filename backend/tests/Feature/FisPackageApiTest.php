<?php

namespace Tests\Feature;

use App\Models\ApplicantApplication;
use App\Models\Classroom;
use App\Models\EducationProgram;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Graduate;
use App\Models\Group;
use App\Models\Specialty;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CompletesStudentCard;
use Tests\TestCase;

class FisPackageApiTest extends TestCase
{
    use CompletesStudentCard;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withApiAuth();
    }

    public function test_it_creates_validates_exports_and_archives_admission_package(): void
    {
        $program = $this->program();
        ApplicantApplication::create(['education_program_id' => $program->id, 'last_name' => 'Иванова', 'first_name' => 'Анна', 'birth_date' => '2010-03-10', 'phone' => '79990000000', 'email' => 'a@example.test', 'education_base' => '9 классов', 'status' => 'new', 'submitted_at' => '2026-06-20']);

        $response = $this->postJson('/api/fis-packages', ['package_type' => 'admission', 'year' => 2026, 'education_program_id' => $program->id]);
        $response->assertCreated()->assertJsonPath('data.records_count', 1)->assertJsonPath('data.package_type', 'admission');
        $packageId = $response->json('data.id');

        $this->postJson("/api/fis-packages/{$packageId}/validate")->assertOk()->assertJsonPath('data.status', 'ready');
        $this->get("/api/fis-packages/{$packageId}/export.csv")->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->getJson("/api/fis-packages/{$packageId}/export.json")->assertOk()->assertJsonPath('package.type', 'admission');
        $this->postJson("/api/fis-packages/{$packageId}/mark-exported")->assertOk()->assertJsonPath('data.status', 'exported');
        $this->postJson("/api/fis-packages/{$packageId}/archive")->assertOk()->assertJsonPath('data.status', 'archived');
    }

    public function test_it_creates_and_validates_gia_package(): void
    {
        [$program, $group, $student] = $this->studentProgram();
        $this->completeStudentCard($student);
        $subject = Subject::create(['name' => 'ГИА', 'code' => 'GIA']);
        $teacher = Teacher::create(['last_name' => 'Смирнова', 'first_name' => 'Елена', 'is_active' => true]);
        $classroom = Classroom::create(['number' => '201', 'type' => 'Учебная']);
        $exam = Exam::create(['academic_year' => '2026/2027', 'semester' => 2, 'group_id' => $group->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id, 'classroom_id' => $classroom->id, 'exam_date' => '2027-06-20', 'starts_at' => '09:00', 'exam_type' => 'gia', 'status' => 'scheduled']);
        Graduate::create(['student_id' => $student->id, 'group_id' => $group->id, 'education_program_id' => $program->id, 'specialty_id' => $program->specialty_id, 'graduation_year' => 2027, 'qualification' => 'Артист', 'status' => 'ready']);
        $result = ExamResult::create(['exam_id' => $exam->id, 'student_id' => $student->id, 'result' => 'отлично', 'score' => 5, 'status' => 'passed']);

        $packageId = $this->postJson('/api/fis-packages', ['package_type' => 'gia', 'year' => 2027, 'education_program_id' => $program->id])->assertCreated()->json('data.id');

        // Единица отчётности — результат студента, а не экзамен.
        $this->assertDatabaseHas('fis_records', [
            'fis_package_id' => $packageId,
            'exam_id' => $exam->id,
            'student_id' => $student->id,
            'exam_result_id' => $result->id,
        ]);

        $this->postJson("/api/fis-packages/{$packageId}/validate")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.records.0.payload.result', 'отлично')
            ->assertJsonPath('data.records.0.payload.score', 5)
            ->assertJsonPath('data.records.0.payload.person', 'Иванов Иван');
    }

    public function test_gia_package_reports_an_exam_without_results(): void
    {
        [$program, $group, $student] = $this->studentProgram();
        $this->completeStudentCard($student);
        $subject = Subject::create(['name' => 'ГИА', 'code' => 'GIA']);
        $teacher = Teacher::create(['last_name' => 'Смирнова', 'first_name' => 'Елена', 'is_active' => true]);
        $classroom = Classroom::create(['number' => '201', 'type' => 'Учебная']);
        Exam::create(['academic_year' => '2026/2027', 'semester' => 2, 'group_id' => $group->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id, 'classroom_id' => $classroom->id, 'exam_date' => '2027-06-20', 'starts_at' => '09:00', 'exam_type' => 'gia', 'status' => 'scheduled']);

        $packageId = $this->postJson('/api/fis-packages', ['package_type' => 'gia', 'year' => 2027, 'education_program_id' => $program->id])->assertCreated()->json('data.id');

        // Раньше такой экзамен попадал в пакет как «готовая» запись без единого
        // человека внутри и молча уходил бы в выгрузку.
        $this->postJson("/api/fis-packages/{$packageId}/validate")
            ->assertOk()
            ->assertJsonPath('data.status', 'validation_failed');

        $this->assertDatabaseHas('fis_validation_errors', [
            'fis_package_id' => $packageId,
            'field' => 'exam_results',
        ]);
    }

    public function test_gia_package_blocks_a_student_with_an_incomplete_card(): void
    {
        [$program, $group, $student] = $this->studentProgram();
        $subject = Subject::create(['name' => 'ГИА', 'code' => 'GIA']);
        $teacher = Teacher::create(['last_name' => 'Смирнова', 'first_name' => 'Елена', 'is_active' => true]);
        $classroom = Classroom::create(['number' => '201', 'type' => 'Учебная']);
        $exam = Exam::create(['academic_year' => '2026/2027', 'semester' => 2, 'group_id' => $group->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id, 'classroom_id' => $classroom->id, 'exam_date' => '2027-06-20', 'starts_at' => '09:00', 'exam_type' => 'gia', 'status' => 'scheduled']);
        ExamResult::create(['exam_id' => $exam->id, 'student_id' => $student->id, 'result' => 'отлично', 'score' => 5, 'status' => 'passed']);

        $packageId = $this->postJson('/api/fis-packages', ['package_type' => 'gia', 'year' => 2027, 'education_program_id' => $program->id])->json('data.id');

        $this->postJson("/api/fis-packages/{$packageId}/validate")->assertOk()->assertJsonPath('data.status', 'validation_failed');
        $this->postJson("/api/fis-packages/{$packageId}/mark-exported")->assertStatus(422);
    }

    public function test_validation_reports_missing_admission_data(): void
    {
        $program = $this->program();
        ApplicantApplication::create(['education_program_id' => $program->id, 'last_name' => 'Петров', 'first_name' => 'Петр', 'status' => 'new', 'submitted_at' => '2026-06-20']);
        $packageId = $this->postJson('/api/fis-packages', ['package_type' => 'admission', 'year' => 2026, 'education_program_id' => $program->id])->json('data.id');

        $this->postJson("/api/fis-packages/{$packageId}/validate")->assertOk()->assertJsonPath('data.status', 'validation_failed');
        $this->assertDatabaseHas('fis_validation_errors', ['fis_package_id' => $packageId, 'field' => 'birth_date']);
    }

    private function program(): EducationProgram
    {
        $specialty = Specialty::create(['code' => '53.02.04', 'name' => 'Вокальное искусство', 'education_level' => 'СПО', 'qualification' => 'Артист']);
        return EducationProgram::create(['specialty_id' => $specialty->id, 'name' => 'ППССЗ Вокальное искусство', 'year_start' => 2026, 'study_form' => 'Очная', 'study_years' => 4, 'is_active' => true]);
    }

    private function studentProgram(): array
    {
        $program = $this->program();
        $group = Group::create(['name' => 'ВИ-401', 'specialty' => 'Вокальное искусство', 'education_program_id' => $program->id, 'course' => 4, 'year_start' => 2023]);
        $student = Student::create(['group_id' => $group->id, 'last_name' => 'Иванов', 'first_name' => 'Иван', 'birth_date' => '2007-05-10', 'status' => 'active']);
        return [$program, $group, $student];
    }
}
