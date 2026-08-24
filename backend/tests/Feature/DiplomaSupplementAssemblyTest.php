<?php

namespace Tests\Feature;

use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\EducationProgram;
use App\Models\Graduate;
use App\Models\Group;
use App\Models\SemesterGrade;
use App\Models\Specialty;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Сборка приложения к диплому из учебного плана и итоговых оценок.
 *
 * Главное, что здесь закрепляется, — **отказ**. Пустое приложение, напечатанное на
 * бланке строгой отчётности, это испорченный бланк, а их считают поштучно; значит
 * «собирать не из чего» обязано быть отказом с названной причиной, а не пустым списком.
 */
class DiplomaSupplementAssemblyTest extends TestCase
{
    use RefreshDatabase;

    private Group $group;

    private Student $student;

    private Graduate $graduate;

    private int $subjectNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->group = Group::create([
            'name' => 'Фортепиано, набор 2023',
            'specialty' => '53.02.03 Инструментальное исполнительство',
            'year_start' => 2023,
        ]);
        $this->student = Student::create([
            'group_id' => $this->group->id,
            'last_name' => 'Горбачева',
            'first_name' => 'Татьяна',
            'status' => 'active',
        ]);
        $this->graduate = Graduate::create([
            'student_id' => $this->student->id,
            'group_id' => $this->group->id,
            'graduation_year' => 2027,
            'status' => 'draft',
        ]);
    }

    /** Плана нет — приложение не собирается, и портал говорит почему. */
    public function test_without_a_curriculum_it_refuses_with_a_reason(): void
    {
        $response = $this->withApiAuth($this->officeUser())
            ->getJson("/api/graduates/{$this->graduate->id}/supplement/assembled")
            ->assertStatus(422);

        $this->assertStringContainsString('учебный план', (string) $response->json('message'));
    }

    /** Выпускник без учебной карточки — оценки лежат у неё, связать не с чем. */
    public function test_without_a_student_card_it_refuses(): void
    {
        // План есть, чтобы отказ не пришёл по другой причине: проверяется именно карточка.
        $this->curriculum();
        $this->student->delete();

        $response = $this->withApiAuth($this->officeUser())
            ->getJson("/api/graduates/{$this->graduate->id}/supplement/assembled")
            ->assertStatus(422);

        $this->assertStringContainsString('карточкой студента', (string) $response->json('message'));
    }

    /** План есть, а дисциплин в нём нет — то же самое, отказ, а не пустое приложение. */
    public function test_an_empty_curriculum_refuses_too(): void
    {
        $this->curriculum();

        $this->withApiAuth($this->officeUser())
            ->getJson("/api/graduates/{$this->graduate->id}/supplement/assembled")
            ->assertStatus(422);
    }

    /**
     * Дисциплина, идущая три семестра, стоит в приложении **одной строкой** с суммой
     * часов: в плане она разбита по семестрам, в документе — нет.
     */
    public function test_a_discipline_of_three_semesters_becomes_one_row(): void
    {
        $curriculum = $this->curriculum();
        $subject = $this->subject('Сольфеджио');

        foreach ([[1, 60], [2, 72], [3, 48]] as [$semester, $hours]) {
            $this->planRow($curriculum, $subject, $semester, $hours);
        }
        $this->mark($subject, 1, '4');
        $this->mark($subject, 3, '5');

        $data = $this->withApiAuth($this->officeUser())
            ->getJson("/api/graduates/{$this->graduate->id}/supplement/assembled")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data['rows']);
        $this->assertSame(180, $data['rows'][0]['hours']);
        // Последняя выставленная, а не первая: в документ идёт итог, а не начало пути.
        $this->assertSame('5', $data['rows'][0]['value']);
        $this->assertTrue($data['ready']);
    }

    /**
     * Дисциплина без итоговой оценки остаётся в списке и попадает в перечень нехватки:
     * секретарь должен видеть, чего не хватает, а не гадать, почему строк меньше плана.
     */
    public function test_a_discipline_without_a_mark_is_named_as_a_problem(): void
    {
        $curriculum = $this->curriculum();
        $graded = $this->subject('Сольфеджио');
        $ungraded = $this->subject('Гармония');

        $this->planRow($curriculum, $graded, 1, 60);
        $this->planRow($curriculum, $ungraded, 1, 40);
        $this->mark($graded, 1, '5');

        $data = $this->withApiAuth($this->officeUser())
            ->getJson("/api/graduates/{$this->graduate->id}/supplement/assembled")
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $data['rows']);
        $this->assertFalse($data['ready']);
        $this->assertCount(1, $data['problems']);
        $this->assertStringContainsString('Гармония', $data['problems'][0]);
    }

    /** Зачёт — такой же итог, как оценка, и пустым он не считается. */
    public function test_a_credit_counts_as_a_mark(): void
    {
        $curriculum = $this->curriculum();
        $subject = $this->subject('Физическая культура');
        $this->planRow($curriculum, $subject, 1, 40);
        $this->mark($subject, 1, 'зачтено');

        $data = $this->withApiAuth($this->officeUser())
            ->getJson("/api/graduates/{$this->graduate->id}/supplement/assembled")
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['ready']);
        $this->assertSame('зачтено', $data['rows'][0]['value']);
    }

    private function curriculum(): Curriculum
    {
        $specialty = Specialty::create(['code' => '53.02.03', 'name' => 'Инструментальное исполнительство']);
        $program = EducationProgram::create([
            'specialty_id' => $specialty->id,
            'name' => 'Фортепиано',
            'year_start' => 2023,
            'study_form' => 'full_time',
            'is_active' => true,
        ]);
        $curriculum = Curriculum::create([
            'education_program_id' => $program->id,
            'name' => 'Фортепиано 2023',
            'year_start' => 2023,
            'status' => 'active',
        ]);

        $this->group->forceFill(['curriculum_id' => $curriculum->id])->save();

        return $curriculum;
    }

    private function subject(string $name): Subject
    {
        return Subject::create(['name' => $name, 'code' => 'SUBJ'.(++$this->subjectNumber)]);
    }

    private function planRow(Curriculum $curriculum, Subject $subject, int $semester, int $hours): CurriculumSubject
    {
        return CurriculumSubject::create([
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'semester' => $semester,
            'total_hours' => $hours,
            'control_type' => 'exam',
            'sequence' => $semester,
        ]);
    }

    private function mark(Subject $subject, int $semester, string $value): SemesterGrade
    {
        return SemesterGrade::create([
            'student_id' => $this->student->id,
            'subject_id' => $subject->id,
            'group_id' => $this->group->id,
            'academic_year' => '2026/2027',
            'semester' => $semester,
            'value' => $value,
            'set_at' => now(),
        ]);
    }

    private function officeUser(): User
    {
        return $this->createApiUser(null, 'study_records');
    }
}
