<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\Group;
use App\Models\ScheduleLesson;
use App\Models\Subject;
use App\Models\Teacher;
use App\Services\AttendanceAnalysisService;
use App\Support\Time\CollegeTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Разбор посещаемости считает сутки колледжа, а не сутки сервера.
 *
 * `access_events.event_time` — колонка `timestamp` (проверено по схеме стенда
 * 28.08.2026), и лежит в ней UTC. Отбор строился от `startOfDay()` по часам
 * сервера, то есть отрезал первые три часа суток колледжа и прихватывал три
 * часа предыдущих. Проход в начале первого ночи попадал во вчера, а вчерашний
 * поздний вечер — в сегодня.
 *
 * Для колледжа искусств это не редкий край: сетка звонков кончается в 20:15, а
 * последние проходы идут ещё позже — то есть в UTC они уже за полночь.
 *
 * Колонки `lesson_date` у расписания и журнала здесь **намеренно не тронуты**:
 * это тип `date`, часового пояса у него нет, и перевод их только сломал бы.
 */
class AttendanceDayInCollegeTimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withApiAuth();
    }

    public function test_a_pass_after_midnight_belongs_to_the_college_day(): void
    {
        $teacher = Teacher::create(['last_name' => 'Полуночный', 'first_name' => 'Пётр', 'is_active' => true]);

        AccessEvent::create([
            'entity_type' => 'teacher',
            'entity_id' => $teacher->id,
            'direction' => AccessEvent::DIRECTION_IN,
            // 00:30 по колледжу 22 августа — то есть 21:30 UTC двадцать первого.
            'event_time' => '2026-08-21 21:30:00',
            'result' => AccessEvent::RESULT_ALLOWED,
        ]);

        $service = app(AttendanceAnalysisService::class);

        $onTheDay = $service->personDays('teacher', $teacher->id, ['date_from' => '2026-08-22', 'date_to' => '2026-08-22']);
        $dayBefore = $service->personDays('teacher', $teacher->id, ['date_from' => '2026-08-21', 'date_to' => '2026-08-21']);

        $this->assertSame(1, $this->entries($onTheDay), 'проход в начале первого ночи не попал в свой день');
        $this->assertSame(0, $this->entries($dayBefore), 'проход уехал в предыдущий день');
    }

    /** Другой край тех же суток: 23:30 по колледжу — это 20:30 UTC того же числа. */
    public function test_a_late_evening_pass_stays_in_its_own_day(): void
    {
        $teacher = Teacher::create(['last_name' => 'Вечерний', 'first_name' => 'Илья', 'is_active' => true]);

        AccessEvent::create([
            'entity_type' => 'teacher',
            'entity_id' => $teacher->id,
            'direction' => AccessEvent::DIRECTION_IN,
            'event_time' => '2026-08-22 20:30:00',
            'result' => AccessEvent::RESULT_ALLOWED,
        ]);

        $service = app(AttendanceAnalysisService::class);

        $this->assertSame(1, $this->entries($service->personDays('teacher', $teacher->id, ['date_from' => '2026-08-22', 'date_to' => '2026-08-22'])));
        $this->assertSame(0, $this->entries($service->personDays('teacher', $teacher->id, ['date_from' => '2026-08-23', 'date_to' => '2026-08-23'])));
    }


    /**
     * Сутки над колонкой `event_time` нигде не берутся часами сервера.
     *
     * Мест, где день проходной сравнивают с днём человека, больше одного:
     * разбор посещаемости, кабинет куратора, отчёты. Перечислять их в тесте
     * руками — значит однажды разойтись со списком; поэтому проверка читает
     * сам каталог. `startOfDay()` и `endOfDay()` рядом с `event_time` означают
     * сутки сервера, то есть потерянные первые три часа.
     *
     * Окна, отмеряемые от занятия, а не от суток (`JournalService`), сюда не
     * попадают: там нет ни `startOfDay`, ни `endOfDay`.
     */
    public function test_no_day_window_over_access_events_is_built_from_server_hours(): void
    {
        $offenders = [];

        foreach ($this->phpFiles(app_path()) as $file) {
            foreach (file($file) as $number => $line) {
                if (! str_contains($line, 'event_time')) {
                    continue;
                }

                if (str_contains($line, 'startOfDay(') || str_contains($line, 'endOfDay(')) {
                    $offenders[] = str_replace(app_path().'/', '', $file).':'.($number + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'Сутки колледжа берутся у CollegeTime: '.implode(', ', $offenders));
    }

    /** @return array<int, string> */
    private function phpFiles(string $directory): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Пара в 08:30, приход в 08:20 — человек пришёл **за десять минут до**.
     *
     * Время занятия — часы на стене: 08:30 значит половину девятого в колледже.
     * Проходная пишет UTC. Пока их склеивали наивно, 08:30 читалось как 08:30
     * UTC, то есть половина двенадцатого по колледжу, и пришедший за десять
     * минут до пары числился пришедшим за два часа пятьдесят.
     *
     * Проверяются обе стороны порога: пришедший раньше — не опоздал, пришедший
     * на семнадцать минут позже — опоздал ровно на семнадцать. Второе число и
     * есть сторож: при наивной склейке оно превращалось в «пришёл за два часа до».
     */
    public function test_a_lesson_at_half_past_eight_measures_lateness_by_the_wall_clock(): void
    {
        $early = $this->teacherWithLesson('Ранний', '2026-09-14', '08:30', '08:20');
        $late = $this->teacherWithLesson('Поздний', '2026-09-14', '08:30', '08:47');

        $service = app(AttendanceAnalysisService::class);
        $day = ['date_from' => '2026-09-14', 'date_to' => '2026-09-14'];

        $earlyDay = $service->personDays('teacher', $early->id, $day)['data'][0];
        $lateDay = $service->personDays('teacher', $late->id, $day)['data'][0];

        $this->assertSame(0, (int) $earlyDay['late_minutes'], 'пришедший за десять минут до пары не опоздал');
        $this->assertSame(17, (int) $lateDay['late_minutes'], 'опоздание считается по часам колледжа, а не сервера');
    }

    private function teacherWithLesson(string $name, string $date, string $lessonAt, string $entryAt): Teacher
    {
        $teacher = Teacher::create(['last_name' => $name, 'first_name' => 'Преподаватель', 'is_active' => true]);
        $group = Group::create(['name' => 'Г-'.$name, 'specialty' => 'Теория музыки', 'course' => 1, 'year_start' => 2026]);
        $subject = Subject::create(['name' => 'Дисциплина '.$name, 'code' => 'D-'.$name]);

        ScheduleLesson::create([
            'group_id' => $group->id,
            'teacher_id' => $teacher->id,
            'subject_id' => $subject->id,
            'lesson_date' => $date,
            'starts_at' => $lessonAt,
            'ends_at' => '10:00',
        ]);

        AccessEvent::create([
            'entity_type' => 'teacher',
            'entity_id' => $teacher->id,
            'direction' => AccessEvent::DIRECTION_IN,
            'event_time' => CollegeTime::moment($date, $entryAt),
            'result' => AccessEvent::RESULT_ALLOWED,
        ]);

        return $teacher;
    }
    /** @param array<string, mixed> $payload */
    private function entries(array $payload): int
    {
        return collect($payload['data'])->sum(fn (array $day): int => (int) ($day['entries_count'] ?? 0));
    }
}
