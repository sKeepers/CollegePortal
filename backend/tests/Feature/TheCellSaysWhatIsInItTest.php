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
 * **Дальше решение изменилось, и тесты переписаны под него — 01.09.2026, вечер.**
 * Владелец подтвердил, что в расписании есть подгруппы и индивидуальные занятия;
 * тогда строка, попавшая в занятую клетку, — чаще всего не ошибка человека, а
 * пара, которую портал пока не умеет держать. Отказ строке даёт стену ошибок и
 * половину расписания без перечня потерянного, поэтому строка теперь
 * **пропускается с названной причиной**: первая пара клетки встаёт, остальные
 * перечисляются поимённо.
 *
 * **Что охраняется, не изменилось:** человек узнаёт, что стоит в клетке и что с
 * этим делать, и получает **одну** причину, а не две про одно занятие. Изменилось,
 * откуда он это узнаёт: не из ошибки строки, а из причины пропуска.
 */
class TheCellSaysWhatIsInItTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_skip_names_the_lesson_that_occupies_the_cell(): void
    {
        $c = $this->context();
        $this->lesson($c, $c['first']);

        $handler = app(ScheduleImportHandler::class);
        $result = $handler->import($this->row($c, $c['second']), 'create');
        $reason = (string) $handler->lastSkipReason();

        $this->assertSame('skipped', $result, 'строка не отказывает, а пропускается');

        // Кто и что стоит в клетке — иначе человек не поймёт, чему не нашлось места.
        $this->assertStringContainsString('Сольфеджио', $reason);
        $this->assertStringContainsString('Первый', $reason);
        // И что делать: пропуск без выхода не лучше отказа без выхода.
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

        $handler = app(ScheduleImportHandler::class);
        $row = $this->row($c, $c['second']);

        $this->assertSame([], $handler->businessValidationErrors($row), 'ошибок строке больше не выдаётся');
        $this->assertSame('skipped', $handler->import($row, 'create'));
        $this->assertNotNull($handler->lastSkipReason(), 'и причина ровно одна, она же и есть');
    }

    public function test_a_clash_with_someone_elses_lesson_is_still_reported(): void
    {
        // Пропуск по клетке не должен прятать настоящее столкновение: тот же
        // преподаватель в это же время у **другой** группы — отдельная беда, и
        // сказать о ней надо. Она остаётся ошибкой строки, потому что человек не
        // раздваивается, а группа делится.
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

        $this->assertArrayHasKey('teacher_id', $errors, 'занятость преподавателя у другой группы обязана остаться видимой');
        $this->assertArrayNotHasKey('group_id', $errors, 'а про свою же клетку ошибки нет: она уходит в пропуск с причиной');
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
