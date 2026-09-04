<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Group;
use App\Models\LessonTime;
use App\Models\ScheduleLesson;
use App\Models\Subject;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Расписание грузится номерами пар, потому что колледж их так и пишет.
 *
 * Что охраняется, словами. Во всех семи присланных файлах расписания **время не
 * написано ни разу** — стоит только номер пары, от нуля до пятнадцати. Сетка
 * звонков в портале ровно такая же: шестнадцать строк с 07:15 до 20:15,
 * сверено с листом владельца 04.09.2026 — сошлись все шестнадцать. Пересчитывать
 * полторы тысячи номеров в часы руками незачем, если это умеет портал.
 *
 * **Заданное руками время сетка не трогает.** Это не удобство, а условие: без
 * него перенос занятия и замена часа файлом стали бы невозможны. То же правило
 * действует в движке расписания, и запрос к сетке у них теперь один на двоих
 * (`LessonTime::activeByNumber`) — чтобы правило не разошлось в двух местах.
 *
 * Проверка идёт полным путём службы, а не вызовом обработчика: в
 * `UniversalImportService` свой порядок, и кусок пути ничего не обещает про
 * поведение.
 */
class ScheduleLoadsByPairNumberTest extends TestCase
{
    use RefreshDatabase;

    private const HEAD = 'Дата;Номер пары;Время начала;Время окончания;Группа;Преподаватель;Дисциплина;Код дисциплины;Аудитория;Корпус;Тип занятия;Тема';

    public function test_a_row_with_only_a_pair_number_takes_its_hours_from_the_bells(): void
    {
        $this->withApiAuth();
        $this->context();

        $result = $this->load('02.10.2026;3;;;ИСП-101;Петрова Анна Викторовна;Специальность;SPEC-001;201;Главный корпус;Практическое;Из номера пары');

        $this->assertSame(1, $result['created_count'], 'Строка без часов, но с номером пары должна загрузиться.');
        $this->assertSame(0, $result['error_count']);

        $lesson = ScheduleLesson::query()->firstOrFail();
        $this->assertSame('09:40', $lesson->starts_at?->format('H:i'));
        $this->assertSame('10:25', $lesson->ends_at?->format('H:i'));
    }

    /**
     * Обратная сторона, и она важнее удобства: время, написанное руками,
     * побеждает сетку. Без этого перенос занятия и замена часа файлом стали бы
     * невозможны — сетка молча возвращала бы час обратно.
     */
    public function test_the_hours_written_by_hand_win_over_the_bells(): void
    {
        $this->withApiAuth();
        $this->context();

        $this->load('02.10.2026;3;11:00;12:30;ИСП-101;Петрова Анна Викторовна;Специальность;SPEC-001;201;Главный корпус;Практическое;Свой час');

        $lesson = ScheduleLesson::query()->firstOrFail();
        $this->assertSame('11:00', $lesson->starts_at?->format('H:i'), 'Сетка не должна перебивать заданное время.');
        $this->assertSame('12:30', $lesson->ends_at?->format('H:i'));
    }

    /**
     * Номер, которого в сетке нет, обязан назвать себя причиной.
     *
     * Иначе строка падает на «время начала обязательно» — сообщение, по
     * которому человек пойдёт искать пустую клетку времени, которой в его файле
     * и не должно быть.
     */
    public function test_a_pair_number_missing_from_the_bells_says_so(): void
    {
        $this->withApiAuth();
        $this->context();

        $result = $this->load('02.10.2026;99;;;ИСП-101;Петрова Анна Викторовна;Специальность;SPEC-001;201;Главный корпус;Практическое;Нет такой пары');

        $this->assertSame(0, $result['created_count']);
        $this->assertSame(1, $result['error_count']);

        $said = json_encode($result['validation_errors'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('сетке звонков', (string) $said);
        $this->assertStringNotContainsString('Время начала', (string) $said, 'Причина должна быть про номер пары, а не про пустую клетку времени.');
    }

    /**
     * **Этот сторож обязан оставаться зелёным** и на внесённом дефекте не
     * краснеет: он охраняет не новое поведение, а то, что старое не сломалось.
     * Файлы без колонки «Номер пары» грузились всегда и обязаны грузиться
     * дальше — иначе правка ради семи присланных файлов сломает все прежние.
     */
    public function test_a_file_without_the_pair_number_column_still_loads(): void
    {
        $this->withApiAuth();
        $this->context();

        $file = $this->file(
            'Дата;Время начала;Время окончания;Группа;Преподаватель;Дисциплина;Код дисциплины;Аудитория;Корпус;Тип занятия;Тема',
            '02.10.2026;09:00;10:30;ИСП-101;Петрова Анна Викторовна;Специальность;SPEC-001;201;Главный корпус;Практическое;Как раньше',
        );

        $jobId = $this->post('/api/admin/import/preview', ['data_type' => 'schedule', 'file' => $file])
            ->assertCreated()->json('data.id');

        $mapping = $this->mapping();
        unset($mapping['lesson_number']);

        $result = $this->postJson("/api/admin/import/{$jobId}/confirm", ['mode' => 'create', 'mapping' => $mapping])
            ->assertOk()->json('data');

        $this->assertSame(1, $result['created_count']);
        $this->assertSame('09:00', ScheduleLesson::query()->firstOrFail()->starts_at?->format('H:i'));
    }

    private function context(): void
    {
        Teacher::create(['last_name' => 'Петрова', 'first_name' => 'Анна', 'middle_name' => 'Викторовна', 'is_active' => true]);
        Subject::create(['name' => 'Специальность', 'code' => 'SPEC-001']);
        Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]);
        Classroom::create(['number' => '201', 'building' => 'Главный корпус']);
        LessonTime::create(['lesson_number' => 3, 'starts_at' => '09:40:00', 'ends_at' => '10:25:00', 'is_active' => true]);
    }

    /** @return array<string, string|null> */
    private function mapping(): array
    {
        return [
            'lesson_date' => 'Дата',
            'lesson_number' => 'Номер пары',
            'starts_at' => 'Время начала',
            'ends_at' => 'Время окончания',
            'group_name' => 'Группа',
            'teacher_name' => 'Преподаватель',
            'subject_name' => 'Дисциплина',
            'subject_code' => 'Код дисциплины',
            'classroom_number' => 'Аудитория',
            'classroom_building' => 'Корпус',
            'lesson_type' => 'Тип занятия',
            'topic' => 'Тема',
        ];
    }

    private function file(string $head, string $row): UploadedFile
    {
        $path = storage_path('framework/testing/schedule.csv');
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $head."\n".$row."\n");

        return new UploadedFile($path, 'schedule.csv', 'text/csv', null, true);
    }

    /** @return array<string, mixed> */
    private function load(string $row): array
    {
        $jobId = $this->post('/api/admin/import/preview', [
            'data_type' => 'schedule',
            'file' => $this->file(self::HEAD, $row),
        ])->assertCreated()->json('data.id');

        return $this->postJson("/api/admin/import/{$jobId}/confirm", [
            'mode' => 'create',
            'mapping' => $this->mapping(),
        ])->assertOk()->json('data');
    }
}
