<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Group;
use App\Models\ScheduleEntry;
use App\Models\ScheduleLesson;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingLoad;
use App\Models\TeachingLoadItem;
use App\Services\Import\ScheduleImportHandler;
use App\Services\ScheduleEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Находка 9 аудита от 08.08.2026: расписание, загруженное импортом, не шло
 * в покрытие часов нагрузки.
 *
 * Импорт создавал только legacy-запись `ScheduleLesson`, а покрытие считается
 * по `ScheduleEntry`. Заведующий загружал расписание семестра файлом, видел
 * занятия в расписании и в журнале, а на экране покрытия — «запланировано
 * 0 часов из 72», и расставлял нагрузку второй раз руками.
 */
class ScheduleImportEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_imported_lesson_counts_towards_teaching_load_coverage(): void
    {
        $context = $this->context();
        $item = $this->loadItem($context, plannedHours: 72);

        $result = app(ScheduleImportHandler::class)->import($this->row($context), ScheduleImportHandler::MODE_SKIP_DUPLICATES);

        $this->assertSame('created', $result);

        // Запись движка создана, а legacy-запись пришла её зеркалом.
        $entry = ScheduleEntry::query()->firstOrFail();
        $this->assertSame($context['group']->id, $entry->group_id);
        $this->assertSame($item->id, $entry->teaching_load_item_id);

        $lesson = ScheduleLesson::query()->firstOrFail();
        $this->assertSame($entry->id, $lesson->schedule_entry_id);

        $coverage = collect(app(ScheduleEngineService::class)->coverage())
            ->firstWhere('teaching_load_item_id', $item->id);

        $this->assertSame(72, $coverage['planned_hours']);
        $this->assertSame(2, $coverage['scheduled_hours'], 'Полтора часа занятия — это два академических часа');
        $this->assertSame('partially_scheduled', $coverage['status']);
    }

    /**
     * Движок отказывается заводить занятие, если дисциплины нет в нагрузке.
     * Файлы, которые грузились до перевода импорта на движок, обязаны грузиться
     * и после — иначе исправление покрытия ломает саму загрузку.
     */
    public function test_lesson_without_teaching_load_still_imports_the_old_way(): void
    {
        $context = $this->context();

        $result = app(ScheduleImportHandler::class)->import($this->row($context), ScheduleImportHandler::MODE_SKIP_DUPLICATES);

        $this->assertSame('created', $result);
        $this->assertSame(1, ScheduleLesson::query()->count());
        $this->assertSame(0, ScheduleEntry::query()->count());
        $this->assertNull(ScheduleLesson::query()->value('schedule_entry_id'));
    }

    /**
     * Повторная загрузка того же файла не должна плодить вторую запись движка.
     */
    public function test_repeated_import_updates_the_engine_entry_instead_of_duplicating(): void
    {
        $context = $this->context();
        $this->loadItem($context, plannedHours: 72);
        $handler = app(ScheduleImportHandler::class);

        $handler->import($this->row($context), ScheduleImportHandler::MODE_SKIP_DUPLICATES);
        $second = $handler->import($this->row($context, ['topic' => 'Уточнённая тема']), ScheduleImportHandler::MODE_UPDATE);

        $this->assertSame('updated', $second);
        $this->assertSame(1, ScheduleEntry::query()->count());
        $this->assertSame(1, ScheduleLesson::query()->count());
        $this->assertSame('Уточнённая тема', ScheduleEntry::query()->value('comment'));
    }

    /**
     * Выгрузка расписания и обратная загрузка: критерий из задания — файл
     * принимается импортом без единой правки.
     */
    public function test_exported_file_is_accepted_by_the_import_unchanged(): void
    {
        $context = $this->context();
        $this->loadItem($context, plannedHours: 72);
        app(ScheduleImportHandler::class)->import($this->row($context), ScheduleImportHandler::MODE_SKIP_DUPLICATES);

        $this->withApiAuth();
        $response = $this->get('/api/schedule-lessons/export');
        $response->assertOk();

        $csv = $response->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(2, $lines, 'Заголовок и одна строка занятия');

        $handler = app(ScheduleImportHandler::class);
        $headers = array_map(fn (string $value): string => trim($value, "\u{FEFF}\" \r"), explode(';', $lines[0]));
        $this->assertSame($handler->templateHeaders(), $headers, 'Колонки выгрузки должны совпадать с шаблоном импорта');

        // Строка выгрузки, разобранная теми же полями, что и при импорте,
        // должна пройти подготовку и найти те же группу, преподавателя и дисциплину.
        $values = array_map(fn (string $value): string => trim($value, '"'), explode(';', $lines[1]));
        $prepared = $handler->prepare([
            'lesson_date' => $values[0],
            'starts_at' => $values[1],
            'ends_at' => $values[2],
            'group_name' => $values[3],
            'teacher_name' => $values[4],
            'subject_name' => $values[5],
            'subject_code' => $values[6],
            'classroom_number' => $values[7],
            'classroom_building' => $values[8],
            'lesson_type' => $values[9],
            'topic' => $values[10],
        ]);

        $this->assertSame($context['group']->id, $prepared['group_id']);
        $this->assertSame($context['teacher']->id, $prepared['teacher_id']);
        $this->assertSame($context['subject']->id, $prepared['subject_id']);
        $this->assertSame($context['classroom']->id, $prepared['classroom_id']);
        $this->assertNotNull($handler->findExisting($prepared), 'Загруженная обратно строка должна опознаться как та же самая');
    }

    public function test_a_room_number_in_two_buildings_is_refused_not_guessed(): void
    {
        // 30.08.2026 в портал приходят Голенева и Серова, и номера в них
        // повторяются. До правки строка с пустым корпусом получала первую
        // попавшуюся аудиторию и загружалась молча — занятие оказывалось в
        // другом корпусе, и узнали бы об этом у двери.
        $context = $this->context();
        Classroom::create(['number' => '101', 'building' => 'Голенева, 21']);
        Classroom::create(['number' => '101', 'building' => 'Серова, 277']);

        $prepared = $this->row($context, ['classroom_number' => '101', 'classroom_building' => null]);

        $errors = Validator::make($prepared, app(ScheduleImportHandler::class)->rules())->errors();

        $this->assertTrue($errors->has('classroom_id'));
        $this->assertStringContainsString('в нескольких корпусах', $errors->first('classroom_id'));
        // Сообщение именно о споре, а не «не найдена»: иначе человек пойдёт
        // искать опечатку в номере, которой нет.
        $this->assertStringNotContainsString('не найдена', $errors->first('classroom_id'));
    }

    public function test_the_building_column_picks_the_right_room_of_two(): void
    {
        // Обратная сторона: отказ не должен превратиться в «нельзя никогда».
        // С названным корпусом пара однозначна, и строка обязана пройти.
        $context = $this->context();
        Classroom::create(['number' => '101', 'building' => 'Голенева, 21']);
        $serova = Classroom::create(['number' => '101', 'building' => 'Серова, 277']);

        $prepared = $this->row($context, ['classroom_number' => '101', 'classroom_building' => 'Серова, 277']);

        $this->assertSame($serova->id, $prepared['classroom_id']);
        $this->assertFalse(
            Validator::make($prepared, app(ScheduleImportHandler::class)->rules())->errors()->has('classroom_id'),
        );
    }

    public function test_a_file_without_the_building_column_still_loads(): void
    {
        // **Этот сторож обязан оставаться зелёным** и на внесённом дефекте не
        // краснеет — он охраняет не поведение при споре, а то, что спора нет,
        // пока номер один на портал. Без него правка ради 30.08 могла бы
        // отвергать файлы, которые грузились всегда.
        $context = $this->context();

        $prepared = $this->row($context, ['classroom_building' => null]);

        $this->assertSame($context['classroom']->id, $prepared['classroom_id']);
        $this->assertFalse(
            Validator::make($prepared, app(ScheduleImportHandler::class)->rules())->errors()->has('classroom_id'),
        );
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

    /** @param array<string, mixed> $context */
    private function loadItem(array $context, int $plannedHours): TeachingLoadItem
    {
        $load = TeachingLoad::create([
            'academic_year' => '2026/2027',
            'teacher_id' => $context['teacher']->id,
            'group_id' => $context['group']->id,
            'status' => 'active',
        ]);

        return TeachingLoadItem::create([
            'teaching_load_id' => $load->id,
            'subject_id' => $context['subject']->id,
            'group_id' => $context['group']->id,
            'teacher_id' => $context['teacher']->id,
            'semester' => 1,
            'hours_total' => $plannedHours,
            'planned_hours' => $plannedHours,
        ]);
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
