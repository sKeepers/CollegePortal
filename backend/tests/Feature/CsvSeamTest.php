<?php

namespace Tests\Feature;

use App\Models\Curriculum;
use App\Models\EducationProgram;
use App\Models\Exam;
use App\Models\Group;
use App\Models\Specialty;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingLoad;
use App\Support\Csv\CsvExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Шов между выгрузкой и загрузкой CSV.
 *
 * Проверяет ровно то, что разъезжалось: маркер порядка байтов писала половина
 * выгрузок, а снимала половина импортов. Файл, отданный выгрузкой, обязан
 * приниматься импортом и после того, как человек открыл его в Excel и сохранил.
 */
class CsvSeamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    /**
     * Без маркера Excel читает UTF-8 как ANSI и показывает кириллицу
     * кракозябрами. Раньше его писали девять выгрузок из тринадцати.
     */
    public function test_every_export_starts_with_a_byte_order_mark(): void
    {
        $paths = [
            'students/export', 'teachers/export', 'groups/export', 'subjects/export',
            'classrooms/export', 'specialties/export', 'education-programs/export',
            'employees/export', 'applicant-applications/export',
            'curricula/export', 'exams/export', 'graduates/export', 'teaching-loads/export',
            'admin/demo-data/export',
        ];

        foreach ($paths as $path) {
            $response = $this->get('/api/'.$path);

            $this->assertSame(200, $response->getStatusCode(), $path.' не отдалась');
            $this->assertStringStartsWith(
                CsvExport::BOM,
                $response->streamedContent(),
                $path.' отдаётся без маркера порядка байтов',
            );
        }
    }

    /**
     * Excel ставит маркер при сохранении в «CSV UTF-8». Импорт его не снимал, и
     * первая колонка — id, по которой отличают обновление от создания, —
     * терялась: каждая строка файла создавала новую запись.
     */
    public function test_teaching_load_import_updates_instead_of_duplicating_after_excel(): void
    {
        $this->makeReferences();

        $load = TeachingLoad::create(['academic_year' => '2026/2027', 'teacher_id' => Teacher::first()->id, 'status' => 'draft']);
        $load->items()->create(['subject_id' => Subject::first()->id, 'group_id' => Group::first()->id, 'semester' => 1, 'hours_total' => 72, 'load_type' => 'Аудиторная', 'sort_order' => 10]);
        $load->items()->create(['subject_id' => Subject::first()->id, 'group_id' => Group::first()->id, 'semester' => 2, 'hours_total' => 36, 'load_type' => 'Аудиторная', 'sort_order' => 20]);

        $csv = $this->get('/api/teaching-loads/export')->streamedContent();

        $this->post('/api/teaching-loads/import', ['file' => $this->file($csv)])->assertOk();
        $this->assertSame(1, TeachingLoad::count(), 'обратная загрузка выгруженного файла создала дубликаты');

        $this->post('/api/teaching-loads/import', ['file' => $this->file($this->savedByExcel($csv))])->assertOk();
        $this->assertSame(1, TeachingLoad::count(), 'файл после Excel создал дубликаты нагрузки');
    }

    public function test_curriculum_import_updates_instead_of_duplicating_after_excel(): void
    {
        $this->makeReferences();

        $curriculum = Curriculum::create([
            'education_program_id' => EducationProgram::first()->id,
            'code' => null,
            'name' => 'Учебный план Фортепиано 2026',
            'year_start' => 2026,
            'status' => 'draft',
        ]);
        $curriculum->items()->create(['subject_id' => Subject::first()->id, 'course' => 1, 'semester' => 1, 'hours_total' => 144, 'sort_order' => 10]);
        $curriculum->items()->create(['subject_id' => Subject::first()->id, 'course' => 1, 'semester' => 2, 'hours_total' => 72, 'sort_order' => 20]);

        $csv = $this->get('/api/curricula/export')->streamedContent();

        $this->post('/api/curricula/import', ['file' => $this->file($csv)])->assertOk();
        $this->assertSame(1, Curriculum::count(), 'обратная загрузка выгруженного файла создала дубликаты');

        $this->post('/api/curricula/import', ['file' => $this->file($this->savedByExcel($csv))])->assertOk();
        $this->assertSame(1, Curriculum::count(), 'файл после Excel создал дубликаты учебного плана');
    }

    public function test_exam_import_updates_instead_of_duplicating_after_excel(): void
    {
        $this->makeReferences();

        $exam = Exam::create([
            'academic_year' => '2026/2027', 'semester' => 1,
            'group_id' => Group::first()->id, 'subject_id' => Subject::first()->id, 'teacher_id' => Teacher::first()->id,
            'exam_date' => '2026-12-20', 'exam_type' => 'exam', 'status' => 'scheduled',
        ]);
        $exam->results()->create(['student_id' => Student::first()->id, 'result' => '5', 'score' => 95, 'status' => 'passed']);

        $csv = $this->get('/api/exams/export')->streamedContent();

        $this->post('/api/exams/import', ['file' => $this->file($csv)])->assertOk();
        $this->assertSame(1, Exam::count(), 'обратная загрузка выгруженного файла создала дубликаты');

        $this->post('/api/exams/import', ['file' => $this->file($this->savedByExcel($csv))])->assertOk();
        $this->assertSame(1, Exam::count(), 'файл после Excel создал дубликаты экзамена');
    }

    /**
     * Excel в другой локали сохраняет с запятой. Остальные импорты портала это
     * уже понимали, а написанные в контроллерах требовали точку с запятой.
     */
    public function test_import_accepts_a_comma_delimited_file(): void
    {
        $this->makeReferences();

        $load = TeachingLoad::create(['academic_year' => '2026/2027', 'teacher_id' => Teacher::first()->id, 'status' => 'draft']);
        $load->items()->create(['subject_id' => Subject::first()->id, 'group_id' => Group::first()->id, 'semester' => 1, 'hours_total' => 72, 'load_type' => 'Аудиторная', 'sort_order' => 10]);

        $csv = $this->get('/api/teaching-loads/export')->streamedContent();
        $comma = str_replace(';', ',', $csv);

        $response = $this->post('/api/teaching-loads/import', ['file' => $this->file($comma)])->assertOk();

        $this->assertSame([], $response->json('data.errors'), 'файл с запятой не разобран');
        $this->assertSame(1, TeachingLoad::count());
    }

    /** Excel при сохранении в «CSV UTF-8» ставит маркер порядка байтов в начало. */
    private function savedByExcel(string $csv): string
    {
        return str_starts_with($csv, CsvExport::BOM) ? $csv : CsvExport::BOM.$csv;
    }

    private function file(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('export.csv', $content);
    }

    private function makeReferences(): void
    {
        $specialty = Specialty::create(['code' => '53.02.03', 'name' => 'Инструментальное исполнительство', 'education_level' => 'СПО', 'qualification' => 'Артист', 'normative_study_years' => 4]);
        $program = EducationProgram::create(['specialty_id' => $specialty->id, 'name' => 'Фортепиано', 'year_start' => 2026, 'study_form' => 'full_time', 'study_years' => 4, 'is_active' => true]);
        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026, 'education_program_id' => $program->id]);
        Subject::create(['name' => 'Специальность', 'code' => 'SPEC-001']);
        Teacher::create(['last_name' => 'Петрова', 'first_name' => 'Анна', 'middle_name' => 'Викторовна', 'is_active' => true]);
        Student::create(['group_id' => $group->id, 'last_name' => 'Иванов', 'first_name' => 'Иван', 'status' => 'active']);
    }
}
