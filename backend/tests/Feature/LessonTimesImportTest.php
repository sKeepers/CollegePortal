<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\LessonTime;
use App\Models\Subject;
use App\Models\Teacher;
use App\Services\Import\LessonTimeImportHandler;
use App\Services\ScheduleEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Сетка звонков и подстановка времени пары в расписание.
 *
 * Смысл сетки в том, что время перестаёт набираться руками у каждой строки: при
 * шестидесяти пяти группах это тысячи вводов, и опечатка в них не видна.
 */
class LessonTimesImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_bell_grid_row_is_imported_and_time_is_normalised(): void
    {
        $handler = new LessonTimeImportHandler();

        $row = $handler->prepare([
            'lesson_number' => '1',
            'starts_at' => '8:30:00',
            'ends_at' => '10:05',
            'label' => 'Первая пара',
            'is_active' => '',
        ]);

        $this->assertSame('08:30', $row['starts_at'], 'Время из Excel не приведено к ЧЧ:ММ.');
        $this->assertTrue($row['is_active'], 'Пустая клетка «Действует» должна значить «да».');

        $handler->import($row, 'create');

        $bell = LessonTime::query()->where('lesson_number', 1)->firstOrFail();
        $this->assertSame('08:30', $bell->startsAtShort());
        $this->assertSame('10:05', $bell->endsAtShort());
    }

    public function test_schedule_takes_lesson_time_from_the_bell_grid(): void
    {
        LessonTime::create(['lesson_number' => 2, 'starts_at' => '10:15', 'ends_at' => '11:50']);

        $preview = app(ScheduleEngineService::class)->preview($this->entry(['lesson_number' => 2]));

        $this->assertSame('10:15', $preview['entry']['starts_at']);
        $this->assertSame('11:50', $preview['entry']['ends_at']);
    }

    public function test_time_written_by_hand_is_not_overwritten_by_the_grid(): void
    {
        LessonTime::create(['lesson_number' => 2, 'starts_at' => '10:15', 'ends_at' => '11:50']);

        $preview = app(ScheduleEngineService::class)->preview($this->entry([
            'lesson_number' => 2,
            'starts_at' => '10:30',
            'ends_at' => '12:00',
        ]));

        $this->assertSame('10:30', $preview['entry']['starts_at'], 'Перенос занятия должен оставаться возможным.');
        $this->assertSame('12:00', $preview['entry']['ends_at']);
    }

    /** @return array<string, mixed> */
    private function entry(array $overrides): array
    {
        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]);
        $subject = Subject::create(['name' => 'Сольфеджио']);
        $teacher = Teacher::create(['last_name' => 'Смирнова', 'first_name' => 'Елена', 'is_active' => true]);

        return array_merge([
            'group_id' => $group->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'date' => '2026-09-01',
        ], $overrides);
    }
}
