<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Group;
use App\Models\Subject;
use App\Models\Teacher;
use App\Services\Import\ScheduleImportHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Имя зала завуч набирает руками, и портал это переживает.
 *
 * 01.09.2026 у колледжа появились аудитории без номера: «Большой зал» на Голеневой и
 * «Концертный зал» на Крупской. Пока аудитории звались числами, точного сравнения
 * хватало; имя, набранное руками, ломает его на пустом месте — «Большой  зал» с двумя
 * пробелами и «большой зал» со строчной для человека одно и то же.
 *
 * **Синонимы не сводятся, и это половина проверки.** «БЗ» и «Большой зал» портал считать
 * одним не должен: угаданная аудитория ставит группу не туда и делает это молча. Строка
 * с незнакомым именем обязана отказать — так завтра и станет видно, что переименовать.
 */
class HallNameTypedByHandTest extends TestCase
{
    use RefreshDatabase;

    public function test_extra_spaces_and_case_do_not_hide_the_hall(): void
    {
        $this->hall('Большой зал', 'Голенева');

        foreach (['Большой зал', 'большой  зал', 'БОЛЬШОЙ ЗАЛ', '  Большой зал  '] as $typed) {
            $data = $this->row($typed);

            $this->assertIsInt($data['classroom_id'], "«{$typed}» не нашлось");
            $this->assertGreaterThan(0, $data['classroom_id'], "«{$typed}» не нашлось");
        }
    }

    /** А сокращение — не то же имя, и оно обязано отказать, а не угадаться. */
    public function test_an_abbreviation_is_not_the_same_hall(): void
    {
        $this->hall('Большой зал', 'Голенева');

        $data = $this->row('БЗ');

        $this->assertSame(ScheduleImportHandler::CLASSROOM_NOT_FOUND, $data['classroom_id'],
            'сокращение не должно сводиться к полному имени: это угадывание');
    }

    /** Один и тот же зал в двух корпусах различается корпусом, и тоже не по регистру. */
    public function test_the_building_is_matched_the_same_forgiving_way(): void
    {
        $this->hall('Большой зал', 'Голенева');
        $this->hall('Большой зал', 'Крупской');

        $this->assertSame(ScheduleImportHandler::CLASSROOM_AMBIGUOUS, $this->row('Большой зал')['classroom_id'],
            'без корпуса зал в двух корпусах обязан отказать, а не выбрать первый');

        $data = $this->row('Большой зал', 'крупской');
        $this->assertIsInt($data['classroom_id']);
        $this->assertGreaterThan(0, $data['classroom_id'], 'корпус, набранный со строчной, обязан находиться');
    }

    private function hall(string $number, string $building): Classroom
    {
        return Classroom::create(['number' => $number, 'building' => $building]);
    }

    /** @return array<string, mixed> */
    private function row(string $classroom, ?string $building = null): array
    {
        $group = Group::firstOrCreate(['name' => 'ТМ-1'], ['specialty' => 'Теория музыки', 'course' => 1, 'year_start' => 2026]);
        $teacher = Teacher::firstOrCreate(['last_name' => 'Петрова', 'first_name' => 'Анна'], ['middle_name' => 'Викторовна', 'is_active' => true]);
        $subject = Subject::firstOrCreate(['name' => 'Сольфеджио'], ['code' => 'SOLF-1']);

        return app(ScheduleImportHandler::class)->prepare([
            'lesson_date' => '02.09.2026',
            'starts_at' => '09:00',
            'ends_at' => '10:30',
            'group_name' => $group->name,
            'teacher_name' => 'Петрова Анна Викторовна',
            'subject_name' => $subject->name,
            'classroom_number' => $classroom,
            'classroom_building' => $building,
            'lesson_type' => 'Практическое',
        ]);
    }
}
