<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Group;
use App\Models\ScheduleLesson;
use App\Models\Subject;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Перенос занятия файлом: чего он не делает и как об этом говорит.
 *
 * Что охраняется — словами, до чисел. Человек правит расписание тем же
 * файлом, которым его завёл, и меняет в строке время. Портал перенести
 * занятие не может: время начала входит в опознание (`keyFields`), и строка
 * с новым временем — для него другое занятие. **Это не чинится сообщением,
 * и сообщение этого не скрывает.** Оно обязано назвать, где занятие стоит
 * сейчас, и сказать правду про режим создания: тот загрузит строку, а
 * прежняя пара останется.
 *
 * Почему это важнее вежливости. До 03.09.2026 пропуск отвечал общим текстом
 * «...выберите режим создания» — совет, который приводит ровно к молчаливому
 * двойнику: замер полным путём (файл → предпросмотр → подтверждение) дал в
 * режиме создания `создано 1, ошибок 0, замечаний 0` и **две** пары у одной
 * группы, в 09:00 и в 11:00. Починка молчаливого пропуска стала дорогой во
 * второй дефект, потому что человек делает то, что ему посоветовали.
 *
 * Проверка идёт полным путём службы, а не вызовом обработчика: в
 * `UniversalImportService` порядок свой, и кусок пути ничего не обещает про
 * поведение.
 */
class ScheduleImportNamesTheLessonItWillNotMoveTest extends TestCase
{
    use RefreshDatabase;

    private const HEAD = 'Дата;Время начала;Время окончания;Группа;Преподаватель;Дисциплина;Код дисциплины;Аудитория;Корпус;Тип занятия;Тема';

    private const AT_NINE = '02.10.2026;09:00;10:30;ИСП-101;Петрова Анна Викторовна;Специальность;SPEC-001;201;Главный корпус;Практическое;Было';

    private const MOVED_TO_ELEVEN = '02.10.2026;11:00;12:30;ИСП-101;Петрова Анна Викторовна;Специальность;SPEC-001;201;Главный корпус;Практическое;Сменили время';

    public function test_a_row_with_a_new_time_says_where_the_lesson_stands_and_what_create_mode_would_do(): void
    {
        $this->withApiAuth();
        $this->context();
        $this->load(self::AT_NINE, 'create');

        $result = $this->load(self::MOVED_TO_ELEVEN, 'update');

        $this->assertSame(0, $result['updated_count']);
        $this->assertSame(1, $result['skipped_count']);
        $this->assertSame(0, $result['error_count']);
        $this->assertSame(1, ScheduleLesson::query()->count(), 'Занятие остаётся одно: перенос файлом не делается.');

        $reason = $this->onlyNotice($result);

        // Где занятие стоит сейчас — иначе человек не поймёт, о чём речь.
        $this->assertStringContainsString('09:00', $reason);
        $this->assertStringContainsString('Специальность', $reason);

        // Правда про режим создания. Ради этой строки проверка и написана.
        $this->assertStringContainsString('прежняя пара останется', $reason);

        // И совета, который ведёт в двойника, здесь быть не должно.
        $this->assertStringNotContainsString('выберите режим создания', $reason);
    }

    /**
     * Встречная сторона: новая ветка не должна проглотить общий случай.
     *
     * Строка, которой в этот день не соответствует вообще ничего, обязана
     * получать прежний общий текст — там совет про режим создания верен, он и
     * заводит недостающую строку. Без этой проверки правку легко расширить на
     * всё подряд, и тогда единственный правильный совет исчезнет из портала.
     */
    public function test_a_row_with_nothing_like_it_still_gets_the_plain_reason(): void
    {
        $this->withApiAuth();
        $this->context();

        $result = $this->load(self::MOVED_TO_ELEVEN, 'update');

        $this->assertSame(1, $result['skipped_count']);
        $this->assertSame(0, ScheduleLesson::query()->count());

        $reason = $this->onlyNotice($result);

        $this->assertStringContainsString('выберите режим создания', $reason);
        $this->assertStringNotContainsString('прежняя пара останется', $reason);
    }

    /** @param array<string, mixed> $result */
    private function onlyNotice(array $result): string
    {
        $notices = (array) ($result['warnings'] ?? []);
        $this->assertCount(1, $notices, 'У пропущенной строки должно быть ровно одно замечание.');

        return (string) ($notices[0]['reason'] ?? '');
    }

    private function context(): void
    {
        Teacher::create(['last_name' => 'Петрова', 'first_name' => 'Анна', 'middle_name' => 'Викторовна', 'is_active' => true]);
        Subject::create(['name' => 'Специальность', 'code' => 'SPEC-001']);
        Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]);
        Classroom::create(['number' => '201', 'building' => 'Главный корпус']);
    }

    /** @return array<string, mixed> */
    private function load(string $row, string $mode): array
    {
        $path = storage_path('framework/testing/schedule.csv');
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, self::HEAD."\n".$row."\n");

        $jobId = $this->post('/api/admin/import/preview', [
            'data_type' => 'schedule',
            'file' => new UploadedFile($path, 'schedule.csv', 'text/csv', null, true),
        ])->assertCreated()->json('data.id');

        return $this->postJson("/api/admin/import/{$jobId}/confirm", [
            'mode' => $mode,
            'mapping' => [
                'lesson_date' => 'Дата',
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
            ],
        ])->assertOk()->json('data');
    }
}
