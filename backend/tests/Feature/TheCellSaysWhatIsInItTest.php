<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Group;
use App\Models\ScheduleLesson;
use App\Models\Subject;
use App\Models\Teacher;
use App\Services\Import\ScheduleImportHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Клетка расписания говорит, что в ней стоит.
 *
 * Замер 01.09.2026 до правки: занятие в базе, в файле та же клетка со сменой
 * преподавателя — и строка получала **две** ошибки, «Группа уже занята в это
 * время» и «Аудитория уже занята в это время». Обе верны по букве и ложны по
 * смыслу: заняты они тем самым занятием, которое строка собиралась изменить, и
 * человек шёл искать чужую пару, которой нет.
 *
 * **Чинится сообщение, а не поведение.** Отказ уместен: менять занятие
 * загрузкой портал не умеет. Сломана была причина отказа.
 */
class TheCellSaysWhatIsInItTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_refusal_names_the_lesson_that_occupies_the_cell(): void
    {
        $c = $this->context();
        $this->lesson($c, $c['first']);

        $errors = app(ScheduleImportHandler::class)
            ->businessValidationErrors($this->row($c, $c['second']));

        $this->assertArrayHasKey('group_id', $errors);
        $reason = $errors['group_id'][0];

        // Кто и что стоит в клетке — иначе человек не поймёт, что заменяет.
        $this->assertStringContainsString('Сольфеджио', $reason);
        $this->assertStringContainsString('Первый', $reason);
        // И что делать: отказ без выхода хуже отказа с выходом.
        $this->assertStringContainsString('на экране расписания', $reason);
        // Прежней неправды больше нет.
        $this->assertStringNotContainsString('Группа уже занята', $reason);
    }

    public function test_one_reason_instead_of_two_from_the_same_lesson(): void
    {
        // «Группа занята» и «Аудитория занята» шли от одной и той же строки.
        // Человек должен получить одну причину, а не две про одно.
        $c = $this->context();
        $this->lesson($c, $c['first']);

        $errors = app(ScheduleImportHandler::class)
            ->businessValidationErrors($this->row($c, $c['second']));

        $this->assertSame(['group_id'], array_keys($errors));
        $this->assertCount(1, $errors['group_id']);
    }

    public function test_a_clash_with_someone_elses_lesson_is_still_reported(): void
    {
        // Сообщение о клетке не должно прятать настоящее столкновение: тот же
        // преподаватель в это же время у **другой** группы — отдельная беда, и
        // сказать о ней надо.
        $c = $this->context();
        $this->lesson($c, $c['first']);

        $other = Group::create(['name' => 'ИСП-102', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]);
        ScheduleLesson::create([
            'lesson_date' => '2026-09-10', 'starts_at' => '09:00', 'ends_at' => '10:30',
            'group_id' => $other->id, 'subject_id' => $c['subject']->id,
            'teacher_id' => $c['second']->id, 'lesson_type' => 'lesson',
        ]);

        $errors = app(ScheduleImportHandler::class)
            ->businessValidationErrors($this->row($c, $c['second']));

        $this->assertArrayHasKey('group_id', $errors);
        $this->assertArrayHasKey('teacher_id', $errors, 'занятость преподавателя у другой группы обязана остаться видимой');
    }

    public function test_an_empty_cell_says_nothing(): void
    {
        // Обратная сторона: там, где клетка свободна, сообщения быть не должно.
        // Без этой проверки первая проходила бы и при отказе на каждой строке.
        $c = $this->context();

        $this->assertSame([], app(ScheduleImportHandler::class)->businessValidationErrors($this->row($c, $c['first'])));
    }

    public function test_the_lesson_that_is_its_own_row_is_not_a_conflict(): void
    {
        // Обновление того же занятия той же строкой: клетка занята им же, и
        // отказывать нельзя — иначе повторная загрузка файла станет ошибкой.
        $c = $this->context();
        $lesson = $this->lesson($c, $c['first']);
        $handler = app(ScheduleImportHandler::class);

        $errors = $this->callConflicts($handler, $this->row($c, $c['first']), $lesson->id);

        $this->assertSame([], $errors);
    }

    /** @param array<string, mixed> $row */
    private function callConflicts(ScheduleImportHandler $handler, array $row, int $ignore): array
    {
        $method = new \ReflectionMethod($handler, 'scheduleConflictMessages');

        return $method->invoke($handler, $row, $ignore);
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return [
            'group' => Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]),
            'subject' => Subject::create(['name' => 'Сольфеджио', 'code' => 'SOLF-001']),
            'first' => Teacher::create(['last_name' => 'Первый', 'first_name' => 'Пётр', 'middle_name' => 'Петрович', 'is_active' => true]),
            'second' => Teacher::create(['last_name' => 'Второй', 'first_name' => 'Семён', 'middle_name' => 'Семёнович', 'is_active' => true]),
            'classroom' => Classroom::create(['number' => '201', 'building' => 'Главный корпус']),
        ];
    }

    /** @param array<string, mixed> $c */
    private function lesson(array $c, Teacher $teacher): ScheduleLesson
    {
        return ScheduleLesson::create([
            'lesson_date' => '2026-09-10', 'starts_at' => '09:00', 'ends_at' => '10:30',
            'group_id' => $c['group']->id, 'subject_id' => $c['subject']->id,
            'teacher_id' => $teacher->id, 'classroom_id' => $c['classroom']->id, 'lesson_type' => 'lesson',
        ]);
    }

    /**
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    private function row(array $c, Teacher $teacher): array
    {
        return app(ScheduleImportHandler::class)->prepare([
            'lesson_date' => '10.09.2026',
            'starts_at' => '09:00',
            'ends_at' => '10:30',
            'group_name' => $c['group']->name,
            'teacher_name' => trim($teacher->last_name.' '.$teacher->first_name.' '.$teacher->middle_name),
            'subject_name' => $c['subject']->name,
            'subject_code' => $c['subject']->code,
            'classroom_number' => $c['classroom']->number,
            'classroom_building' => $c['classroom']->building,
            'lesson_type' => 'Практическое',
            'topic' => 'Проба',
        ]);
    }
}
