<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\JournalLesson;
use App\Models\SemesterGrade;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Итоговая оценка по дисциплине за семестр.
 *
 * Место, которого не было: приложение к диплому, ведомость и справка об обучении
 * собираются из неё, а взять её до 24.08.2026 было неоткуда — в журнале лежит оценка за
 * занятие, в экзамене за экзамен, а дисциплина, кончающаяся зачётом, экзамена не имеет
 * вовсе.
 */
class SemesterGradeTest extends TestCase
{
    use RefreshDatabase;

    private Group $group;

    private Subject $subject;

    private Teacher $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->group = Group::create([
            'name' => 'Фортепиано, набор 2023',
            'specialty' => '53.02.03 Инструментальное исполнительство',
            'year_start' => 2023,
        ]);
        $this->subject = Subject::create(['name' => 'Сольфеджио', 'code' => 'SOLF']);
        $this->teacher = Teacher::create(['last_name' => 'Власова', 'first_name' => 'Ирина', 'status' => 'active']);
    }

    /**
     * Ведомость строится от состава группы, а не от списка оценок: преподавателю нужен
     * весь курс, включая тех, кому он ещё ничего не поставил, — иначе он не увидит, кого
     * пропустил, а в конце семестра это и есть работа.
     */
    public function test_the_sheet_lists_every_student_even_without_marks(): void
    {
        $this->students(3);
        $user = $this->officeUser();

        $response = $this->withApiAuth($user)->getJson($this->sheetUrl())->assertOk();

        $this->assertCount(3, $response->json('data.students'));
        $this->assertNull($response->json('data.students.0.value'));
    }

    public function test_a_mark_is_written_and_the_second_save_replaces_it(): void
    {
        [$student] = $this->students(1);
        $user = $this->officeUser();

        $this->withApiAuth($user)->postJson('/api/semester-grades', $this->payload([
            ['student_id' => $student->id, 'value' => '4'],
        ]))->assertOk()->assertJsonPath('data.saved', 1);

        $this->withApiAuth($user)->postJson('/api/semester-grades', $this->payload([
            ['student_id' => $student->id, 'value' => '5', 'comment' => 'пересдал'],
        ]))->assertOk()->assertJsonPath('data.saved', 1);

        $this->assertDatabaseCount('semester_grades', 1);
        $grade = SemesterGrade::query()->firstOrFail();
        $this->assertSame('5', $grade->value);
        $this->assertSame('пересдал', $grade->comment);
        $this->assertSame($user->id, $grade->set_by);
    }

    /** Зачёт — такой же законный итог, как «5», и числом его не записать. */
    public function test_a_credit_is_a_valid_mark(): void
    {
        [$student] = $this->students(1);

        $this->withApiAuth($this->officeUser())->postJson('/api/semester-grades', $this->payload([
            ['student_id' => $student->id, 'value' => 'зачтено'],
        ]))->assertOk();

        $this->assertSame('зачтено', SemesterGrade::query()->value('value'));
    }

    /**
     * Пустое значение снимает оценку. Поставивший не тому студенту обязан иметь способ
     * это убрать — то же действие, что и в журнале.
     */
    public function test_an_empty_value_removes_the_mark(): void
    {
        [$student] = $this->students(1);
        $user = $this->officeUser();

        $this->withApiAuth($user)->postJson('/api/semester-grades', $this->payload([
            ['student_id' => $student->id, 'value' => '3'],
        ]))->assertOk();

        $this->withApiAuth($user)->postJson('/api/semester-grades', $this->payload([
            ['student_id' => $student->id, 'value' => ''],
        ]))->assertOk()->assertJsonPath('data.removed', 1);

        $this->assertDatabaseCount('semester_grades', 0);
    }

    /** Ведомость правит состав группы, а не произвольный список. */
    public function test_a_student_of_another_group_is_skipped(): void
    {
        $this->students(1);
        $other = Group::create(['name' => 'Хоровое дирижирование, набор 2024', 'specialty' => '53.02.06 Хоровое дирижирование', 'year_start' => 2024]);
        $stranger = Student::create(['group_id' => $other->id, 'last_name' => 'Чужой', 'first_name' => 'Студент', 'status' => 'active']);

        $this->withApiAuth($this->officeUser())->postJson('/api/semester-grades', $this->payload([
            ['student_id' => $stranger->id, 'value' => '5'],
        ]))->assertOk()->assertJsonPath('data.skipped', 1);

        $this->assertDatabaseCount('semester_grades', 0);
    }

    /**
     * Преподаватель ставит итог по своей дисциплине — той, которую вёл. «Вёл» ищется в
     * журнале так же, как и в нагрузке: нагрузка может быть не расписана, а занятия уже
     * прошли.
     */
    public function test_a_teacher_of_the_subject_may_grade(): void
    {
        [$student] = $this->students(1);
        $user = $this->teacherUser();
        $this->lessonTaught();

        $this->withApiAuth($user)->postJson('/api/semester-grades', $this->payload([
            ['student_id' => $student->id, 'value' => '4'],
        ]))->assertOk()->assertJsonPath('data.saved', 1);
    }

    /**
     * А по чужой — нет, и отказ обязан объяснить себя: пустой 403 в журнале уже стоил
     * захода, когда замещающий преподаватель получал отказ без единого слова.
     */
    public function test_a_teacher_who_never_taught_it_is_refused_with_a_reason(): void
    {
        [$student] = $this->students(1);

        $response = $this->withApiAuth($this->teacherUser())
            ->postJson('/api/semester-grades', $this->payload([
                ['student_id' => $student->id, 'value' => '4'],
            ]))
            ->assertForbidden();

        $this->assertStringContainsString('вёл', (string) $response->json('message'));
        $this->assertDatabaseCount('semester_grades', 0);
    }

    /**
     * Учебных планов ещё нет, а оценки уже идут. Ждать плана значило бы не собрать первый
     * семестр вовсе, поэтому часы и форма контроля остаются пустыми, а оценка пишется.
     */
    public function test_a_mark_is_saved_without_a_curriculum(): void
    {
        [$student] = $this->students(1);

        $this->withApiAuth($this->officeUser())->postJson('/api/semester-grades', $this->payload([
            ['student_id' => $student->id, 'value' => '5'],
        ]))->assertOk();

        $grade = SemesterGrade::query()->firstOrFail();
        $this->assertNull($grade->curriculum_subject_id);
        $this->assertNull($grade->control_type);
        $this->assertSame($this->group->id, $grade->group_id);
    }

    /** @return array<int, Student> */
    private function students(int $count): array
    {
        $students = [];

        for ($i = 1; $i <= $count; $i++) {
            $students[] = Student::create([
                'group_id' => $this->group->id,
                'last_name' => 'Студент'.$i,
                'first_name' => 'Имя'.$i,
                'status' => 'active',
            ]);
        }

        return $students;
    }

    private function lessonTaught(): void
    {
        JournalLesson::create([
            'group_id' => $this->group->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
            'lesson_date' => now()->subWeek()->toDateString(),
            'starts_at' => '09:00',
            'ends_at' => '10:30',
            'status' => JournalLesson::STATUS_SIGNED,
        ]);
    }

    private function officeUser(): User
    {
        return $this->createApiUser(null, 'study_records');
    }

    private function teacherUser(): User
    {
        $user = $this->createApiUser(null, 'teacher');
        $this->teacher->forceFill(['user_id' => $user->id])->save();

        return $user;
    }

    private function sheetUrl(): string
    {
        return '/api/semester-grades?'.http_build_query([
            'group_id' => $this->group->id,
            'subject_id' => $this->subject->id,
            'academic_year' => '2026/2027',
            'semester' => 1,
        ]);
    }

    /** @param array<int, array<string, mixed>> $grades */
    private function payload(array $grades): array
    {
        return [
            'group_id' => $this->group->id,
            'subject_id' => $this->subject->id,
            'academic_year' => '2026/2027',
            'semester' => 1,
            'grades' => $grades,
        ];
    }
}
