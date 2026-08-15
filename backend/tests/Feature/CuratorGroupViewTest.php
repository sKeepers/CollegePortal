<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\JournalGrade;
use App\Models\JournalLesson;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Куратор смотрит свою группу как преподаватель — и только смотрит.
 *
 * Решение владельца от 12.08.2026: «Куратор как Преподаватель только в режиме
 * просмотра. Куратор контролирует успеваемость студента», и та же картина нужна
 * на компьютере. Отсюда две половины, которые обязаны держаться вместе: журнал
 * открывает куратору занятия своей группы у **любого** преподавателя, и он же
 * не даёт ему в этих занятиях ничего изменить.
 *
 * Здесь проверяется именно шов. Открыть чтение легко, и ровно так же легко
 * открыть вместе с ним правку: `authorizeLesson` — одна функция на оба случая,
 * и разница между ними в одном параметре.
 */
class CuratorGroupViewTest extends TestCase
{
    use RefreshDatabase;

    /** Всё, чем занятие меняют. Ни один из этих маршрутов куратору не открыт. */
    private const WRITE_ROUTES = [
        ['put', '/api/journal/lessons/%d', ['topic' => 'Подменённая тема']],
        ['put', '/api/journal/lessons/%d/attendance', ['attendance' => [['student_id' => 0, 'status' => 'absent']]]],
        ['put', '/api/journal/lessons/%d/grades', ['grades' => [['student_id' => 0, 'value' => '5']]]],
        ['post', '/api/journal/lessons/%d/complete', []],
        ['post', '/api/journal/lessons/%d/edit-requests', ['reason' => 'Хочу поправить']],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-13 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_curator_sees_lessons_of_their_group_taught_by_someone_else(): void
    {
        $world = $this->world();

        $ids = collect($this->withApiAuth($world['curatorUser'])
            ->getJson('/api/journal/lessons')
            ->assertOk()
            ->json('data'))
            ->pluck('id')
            ->all();

        sort($ids);
        $expected = [$world['ownGroupByOther']->id, $world['ownGroupByCurator']->id];
        sort($expected);

        $this->assertSame($expected, $ids, 'Куратор видит не тот набор занятий.');
        $this->assertNotContains(
            $world['foreignLesson']->id,
            $ids,
            'В журнал куратора попало занятие чужой группы.',
        );

        // Карточка занятия, которое ведёт другой преподаватель, открывается
        // целиком: ради оценок и отметок она куратору и нужна.
        $this->withApiAuth($world['curatorUser'])
            ->getJson("/api/journal/lessons/{$world['ownGroupByOther']->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $world['ownGroupByOther']->id);

        $this->withApiAuth($world['curatorUser'])
            ->getJson("/api/journal/lessons/{$world['foreignLesson']->id}")
            ->assertForbidden();
    }

    public function test_lesson_list_of_the_group_says_what_may_be_edited(): void
    {
        $world = $this->world();

        // Так занятия группы запрашивают оба экрана куратора: фильтром по
        // группе, без своего эндпоинта.
        $rows = collect($this->withApiAuth($world['curatorUser'])
            ->getJson("/api/journal/lessons?group_id={$world['ownGroup']->id}")
            ->assertOk()
            ->json('data'))
            ->keyBy('id');

        $this->assertCount(2, $rows);
        // Признак, по которому экран гасит кнопки правки: своё занятие правится,
        // занятие другого преподавателя — нет.
        $this->assertTrue($rows[$world['ownGroupByCurator']->id]['can_edit']);
        $this->assertFalse($rows[$world['ownGroupByOther']->id]['can_edit']);

        // Чужая группа фильтром не открывается: пустой список, а не отказ.
        $this->withApiAuth($world['curatorUser'])
            ->getJson("/api/journal/lessons?group_id={$world['foreignGroup']->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_curator_cannot_change_a_lesson_of_their_group(): void
    {
        $world = $this->world();
        $lesson = $world['ownGroupByOther'];

        foreach (self::WRITE_ROUTES as [$method, $template, $payload]) {
            $url = sprintf($template, $lesson->id);
            $body = $this->withStudent($payload, $world['students'][0]->id);

            $this->withApiAuth($world['curatorUser'])
                ->json(strtoupper($method), $url, $body)
                ->assertForbidden();
        }

        // Ни тема, ни оценки не изменились: у занятия как стояли две оценки
        // ведущего преподавателя, так и стоят.
        $this->assertSame('Тема ведущего', $lesson->refresh()->topic, 'Тема занятия изменилась.');
        $this->assertSame(2, JournalGrade::query()->where('journal_lesson_id', $lesson->id)->count());
        $this->assertSame(
            ['2', '5'],
            JournalGrade::query()->where('journal_lesson_id', $lesson->id)->pluck('value')->sort()->values()->all(),
        );
    }

    public function test_curator_still_edits_their_own_lesson(): void
    {
        $world = $this->world();

        // Куратор — тот же преподаватель: своё занятие он ведёт как раньше.
        // Без этой проверки запрет легко сделать слишком широким.
        $this->withApiAuth($world['curatorUser'])
            ->putJson("/api/journal/lessons/{$world['ownGroupByCurator']->id}", ['topic' => 'Своя тема'])
            ->assertOk()
            ->assertJsonPath('data.topic', 'Своя тема');

        $this->withApiAuth($world['curatorUser'])
            ->putJson("/api/journal/lessons/{$world['ownGroupByCurator']->id}/grades", [
                'grades' => [['student_id' => $world['students'][0]->id, 'value' => '4']],
            ])
            ->assertOk();
    }

    public function test_performance_counts_journal_grades_of_the_group(): void
    {
        $world = $this->world();

        $data = $this->withApiAuth($world['curatorUser'])
            ->getJson("/api/curator/groups/{$world['ownGroup']->id}/performance")
            ->assertOk()
            ->json('data');

        $this->assertSame(3, $data['summary']['students_count']);
        $this->assertSame(3, $data['summary']['grades_count']);
        // (5 + 4 + 2) / 3 — то же простое среднее, что и в отчёте по группе.
        $this->assertSame(3.67, $data['summary']['average_grade']);
        $this->assertSame(1, $data['summary']['with_failing']);
        $this->assertSame(1, $data['summary']['without_grades']);

        $rows = collect($data['students'])->keyBy('name');
        $this->assertSame(4.5, $rows['Абрамов Пётр']['average_grade']);
        $this->assertSame(1, $rows['Белова Анна']['failing_count']);
        $this->assertFalse($rows['Волков Илья']['has_grades']);

        $this->assertSame('Сольфеджио', $data['subjects'][0]['name']);
    }

    public function test_performance_of_a_foreign_group_is_refused(): void
    {
        $world = $this->world();

        $this->withApiAuth($world['curatorUser'])
            ->getJson("/api/curator/groups/{$world['foreignGroup']->id}/performance")
            ->assertForbidden()
            ->assertJsonPath('message', 'Эта группа не закреплена за вами.');

        $this->withApiAuth($world['curatorUser'])
            ->getJson("/api/curator/groups/{$world['foreignGroup']->id}/students")
            ->assertForbidden();

        $this->withApiAuth($world['curatorUser'])
            ->getJson('/api/curator/groups')
            ->assertOk()
            ->assertJsonCount(1, 'data.groups')
            ->assertJsonPath('data.groups.0.id', $world['ownGroup']->id);
    }

    public function test_expelled_student_leaves_the_roster_and_the_average(): void
    {
        $world = $this->world();
        $world['students'][1]->update(['status' => 'expelled']);

        $data = $this->withApiAuth($world['curatorUser'])
            ->getJson("/api/curator/groups/{$world['ownGroup']->id}/performance")
            ->assertOk()
            ->json('data');

        // Двойка отчисленного остаётся в журнале, но группу больше не портит:
        // список и средний балл обязаны считаться по одному составу.
        $this->assertSame(2, $data['summary']['students_count']);
        $this->assertSame(4.5, $data['summary']['average_grade']);
        $this->assertSame(0, $data['summary']['with_failing']);
    }

    public function test_a_stray_teacher_card_does_not_hide_the_group(): void
    {
        $world = $this->world();

        // Здесь заводилась **вторая карточка той же учётной записи**: на стенде у
        // `teacher@local` их было две, `hasOne` брал первую — пустую, — и куратор
        // не видел собственной группы нигде.
        //
        // С 16.08.2026 второй карточки быть не может: владелец решил свести их в
        // одну, миграция `2026_08_16_000001` развела дубли и закрыла путь
        // частичным уникальным индексом (`OneProfileCardPerAccountTest`). Ровно
        // такую карточку она и оставляет после себя — без учётной записи, но в
        // реестре, — поэтому проверяем, что чужая карточка рядом кураторскую
        // группу по-прежнему не прячет.
        $strayCard = Teacher::create([
            'user_id' => null,
            'last_name' => 'Смирнова', 'first_name' => 'Елена', 'is_active' => true,
        ]);
        $this->assertNotNull($strayCard->id);

        $this->withApiAuth($world['curatorUser'])
            ->getJson('/api/curator/groups')
            ->assertOk()
            ->assertJsonCount(1, 'data.groups');

        $this->withApiAuth($world['curatorUser'])
            ->getJson("/api/journal/lessons/{$world['ownGroupByOther']->id}")
            ->assertOk();
    }

    public function test_section_needs_the_journal_permission(): void
    {
        $world = $this->world();
        $stranger = User::factory()->create([
            'is_active' => true,
            'role_id' => $this->roleWithPermissions('no-journal', ['dashboard.view'])->id,
        ]);

        $this->withApiAuth($stranger)->getJson('/api/curator/groups')->assertForbidden();
        $this->withApiAuth($stranger)
            ->getJson("/api/curator/groups/{$world['ownGroup']->id}/performance")
            ->assertForbidden();
    }

    public function test_whoever_sees_the_whole_journal_sees_any_group(): void
    {
        $world = $this->world();
        $director = User::factory()->create([
            'is_active' => true,
            'role_id' => $this->roleWithPermissions('deputy', ['journal.view', 'journal.view_all'])->id,
        ]);

        $this->withApiAuth($director)
            ->getJson("/api/curator/groups/{$world['foreignGroup']->id}/performance")
            ->assertOk();

        $this->withApiAuth($director)
            ->getJson('/api/curator/groups')
            ->assertOk()
            ->assertJsonCount(2, 'data.groups');
    }

    /**
     * Подставляет настоящий идентификатор студента в заготовку тела запроса.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withStudent(array $payload, int $studentId): array
    {
        foreach (['attendance', 'grades'] as $key) {
            if (isset($payload[$key])) {
                $payload[$key][0]['student_id'] = $studentId;
            }
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        $curatorRole = $this->roleWithPermissions('curator', [
            'journal.view', 'journal.edit', 'journal.attendance', 'journal.grades',
            'journal.complete', 'journal.export', 'students.view', 'groups.view',
        ]);

        $curatorUser = User::factory()->create(['is_active' => true, 'role_id' => $curatorRole->id]);
        $curator = Teacher::create(['user_id' => $curatorUser->id, 'last_name' => 'Смирнова', 'first_name' => 'Елена', 'is_active' => true]);

        $otherUser = User::factory()->create(['is_active' => true, 'role_id' => $curatorRole->id]);
        $other = Teacher::create(['user_id' => $otherUser->id, 'last_name' => 'Петров', 'first_name' => 'Игорь', 'is_active' => true]);

        $ownGroup = Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026, 'curator_id' => $curator->id]);
        $foreignGroup = Group::create(['name' => 'ВОК-201', 'specialty' => 'Вокальное искусство', 'course' => 2, 'year_start' => 2025, 'curator_id' => $other->id]);

        $subject = Subject::create(['name' => 'Сольфеджио']);

        $students = [
            Student::create(['group_id' => $ownGroup->id, 'last_name' => 'Абрамов', 'first_name' => 'Пётр', 'status' => 'active']),
            Student::create(['group_id' => $ownGroup->id, 'last_name' => 'Белова', 'first_name' => 'Анна', 'status' => 'active']),
            Student::create(['group_id' => $ownGroup->id, 'last_name' => 'Волков', 'first_name' => 'Илья', 'status' => 'active']),
        ];

        $ownGroupByOther = $this->lesson($ownGroup, $subject, $other, 'Тема ведущего');
        $ownGroupByCurator = $this->lesson($ownGroup, $subject, $curator, 'Тема куратора');
        $foreignLesson = $this->lesson($foreignGroup, $subject, $other, 'Чужая тема');

        // Оценки стоят у занятия, которое ведёт другой преподаватель: именно их
        // куратор сегодня не видит нигде.
        $this->grade($ownGroupByOther, $students[0], '5', $otherUser);
        $this->grade($ownGroupByCurator, $students[0], '4', $curatorUser);
        $this->grade($ownGroupByOther, $students[1], '2', $otherUser);

        return compact(
            'curatorRole', 'curatorUser', 'curator', 'otherUser', 'other',
            'ownGroup', 'foreignGroup', 'subject', 'students',
            'ownGroupByOther', 'ownGroupByCurator', 'foreignLesson',
        );
    }

    private function lesson(Group $group, Subject $subject, Teacher $teacher, string $topic): JournalLesson
    {
        return JournalLesson::create([
            'group_id' => $group->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'lesson_date' => '2026-07-13',
            'starts_at' => '09:00',
            'ends_at' => '10:30',
            'topic' => $topic,
            'status' => JournalLesson::STATUS_IN_PROGRESS,
        ]);
    }

    private function grade(JournalLesson $lesson, Student $student, string $value, User $by): JournalGrade
    {
        return JournalGrade::create([
            'journal_lesson_id' => $lesson->id,
            'student_id' => $student->id,
            'value' => $value,
            'marked_by' => $by->id,
            'marked_at' => Carbon::parse('2026-07-13 11:00:00'),
        ]);
    }

    /** @param  list<string>  $permissions */
    private function roleWithPermissions(string $code, array $permissions): Role
    {
        $role = Role::query()->firstOrCreate(['code' => $code], ['name' => ucfirst($code)]);
        $ids = collect($permissions)->map(fn (string $permission) => Permission::query()->firstOrCreate(
            ['code' => $permission],
            ['name' => $permission, 'module' => 'Journal', 'description' => null, 'system' => true, 'active' => true],
        )->id);
        $role->permissions()->sync($ids);

        return $role;
    }
}
