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
 * Вторая пара группы в том же часе пропускается с причиной, а не отказывает.
 *
 * Владелец подтвердил 01.09.2026: в расписании есть подгруппы — две пары одной группы
 * в одно время у разных преподавателей — и индивидуальные занятия у отдельных студентов.
 * Признака подгруппы в модели нет, и завести две такие пары портал не может: занятость
 * группы блокирующая в четырёх местах.
 *
 * Пока признака нет, выбор не между «правильно» и «неправильно», а между двумя видами
 * неполноты. Отказ строке даёт стену ошибок про «занята» — про занятия, которых оператор
 * не ставил, — и расписание встаёт наполовину без перечня потерянного. Пропуск с
 * названной причиной ставит первую пару и **перечисляет остальные поимённо**.
 *
 * Занятость при этом не ослаблена: преподаватель и аудитория остаются ошибкой строки,
 * потому что человек и комната не раздваиваются. Делится только группа.
 */
class SubgroupsAreSkippedNotRefusedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_second_lesson_of_a_group_is_skipped_and_named(): void
    {
        $context = $this->context();
        $handler = app(ScheduleImportHandler::class);

        $first = $handler->import($this->row($context), 'create');
        $this->assertSame('created', $first, 'первая пара клетки встаёт');

        $second = $handler->import($this->row($context, [
            'teacher_name' => 'Кузнецов Кирилл Кириллович',
        ]), 'create');

        $this->assertSame('skipped', $second, 'вторая пара не отказывает, а пропускается');
        $this->assertSame(1, ScheduleLesson::count(), 'и не заводится вторым занятием');

        $reason = (string) $handler->lastSkipReason();
        $this->assertStringContainsString('уже стоит занятие', $reason, 'пропуск обязан назвать причину');
        $this->assertStringContainsString('Специальность', $reason, 'и сказать, что именно стоит в этом часе');
    }

    /** Занятость не ослаблена: тот же преподаватель у другой группы в тот же час — ошибка. */
    public function test_a_teacher_in_two_places_is_still_an_error(): void
    {
        $context = $this->context();
        $handler = app(ScheduleImportHandler::class);
        $handler->import($this->row($context), 'create');

        $other = Group::create(['name' => 'ИСП-102', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]);

        $errors = $handler->businessValidationErrors($this->row($context, [
            'group_name' => $other->name,
            'classroom_number' => null,
            'classroom_building' => null,
        ]));

        $this->assertArrayHasKey('teacher_id', $errors, 'преподаватель не раздваивается');
        $this->assertArrayNotHasKey('group_id', $errors, 'а про группу ошибки больше нет');
    }

    /** И аудитория тоже: две группы в одной комнате в один час — ошибка строки. */
    public function test_a_classroom_in_two_places_is_still_an_error(): void
    {
        $context = $this->context();
        $handler = app(ScheduleImportHandler::class);
        $handler->import($this->row($context), 'create');

        $other = Group::create(['name' => 'ИСП-103', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]);
        $otherTeacher = Teacher::create(['last_name' => 'Сидоров', 'first_name' => 'Семён', 'middle_name' => 'Семёнович', 'is_active' => true]);

        $errors = $handler->businessValidationErrors($this->row($context, [
            'group_name' => $other->name,
            'teacher_name' => $otherTeacher->last_name.' '.$otherTeacher->first_name.' '.$otherTeacher->middle_name,
        ]));

        $this->assertArrayHasKey('classroom_id', $errors, 'комната не раздваивается');
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return [
            'teacher' => Teacher::create(['last_name' => 'Петрова', 'first_name' => 'Анна', 'middle_name' => 'Викторовна', 'is_active' => true]),
            'subject' => Subject::create(['name' => 'Специальность', 'code' => 'SPEC-001']),
            'group' => Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]),
            'classroom' => Classroom::create(['number' => '201', 'building' => 'Главный корпус']),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(array $context, array $overrides = []): array
    {
        return app(ScheduleImportHandler::class)->prepare([
            'lesson_date' => '01.09.2026',
            'starts_at' => '09:00',
            'ends_at' => '10:30',
            'group_name' => $context['group']->name,
            'teacher_name' => 'Петрова Анна Викторовна',
            'subject_name' => $context['subject']->name,
            'subject_code' => $context['subject']->code,
            'classroom_number' => $context['classroom']->number,
            'classroom_building' => $context['classroom']->building,
            'lesson_type' => 'Практическое',
            'topic' => 'Вводное занятие',
            ...$overrides,
        ]);
    }
}
