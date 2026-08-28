<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Group;
use App\Models\ScheduleLesson;
use App\Models\AccessEvent;
use App\Models\Subject;
use App\Models\Teacher;
use App\Services\DashboardAnalyticsService;
use App\Support\Time\CollegeTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Недельный ряд на рабочем столе директора: семь точек одним запросом.
 *
 * Раньше каждый день ряда считался отдельным `count()`, и три ряда стоили
 * двадцать один запрос из девяноста трёх на открытие раздела. Здесь
 * закреплено и то, что запросов стало мало, и то, что ряд от этого не
 * испортился: дни без записей обязаны остаться в ряду нулями, а первый день
 * недели — не выпасть из диапазона.
 */
class ExecutiveDashboardSeriesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Свойство объекта, а не `static`: между тестами база откатывается, и
     * пережившие откат модели ссылались бы на несуществующие строки.
     *
     * @var array<string, \Illuminate\Database\Eloquent\Model>|null
     */
    private ?array $context = null;

    public function test_the_week_keeps_seven_points_with_zeroes_in_the_gaps(): void
    {
        // Дни колледжа, а не сервера. С 21:00 UTC до полуночи `now()` называет
        // вчерашнее число, ряд же строится по календарю колледжа — и первый
        // день недели, взятый от `now()`, оказывался бы за границей ряда. Тест
        // краснел бы три часа в сутки и зеленел сам собой.
        $today = CollegeTime::todayDate();
        $firstDay = Carbon::parse($today)->subDays(6)->toDateString();

        // Занятия ровно в первый день недели: на SQLite дата сравнивается как
        // строка, и с нижней границей вида «дата + 00:00:00» этот день выпадал.
        $this->createLesson($firstDay, '09:00', '10:30');
        $this->createLesson($firstDay, '10:40', '12:10');
        $this->createLesson($today, '09:00', '10:30');

        $series = app(DashboardAnalyticsService::class)->executive()['data']['charts']['lessons_7_days'];
        $byDate = collect($series)->pluck('value', 'date');

        $this->assertCount(7, $series, 'В ряду должно остаться семь точек, включая дни без занятий.');
        $this->assertSame(2, (int) $byDate[$firstDay], 'Первый день недели выпал из диапазона.');
        $this->assertSame(1, (int) $byDate[$today]);
        $this->assertSame(0, (int) $byDate[Carbon::parse($today)->subDays(3)->toDateString()], 'День без занятий обязан быть нулём, а не пропуском.');
    }

    /**
     * Проход в первом часу ночи попадает в тот день, когда человек вошёл.
     *
     * Ряд проходов стоит на `access_events.event_time` — колонке `timestamp`,
     * то есть на UTC. Пока день считался `date(event_time)`, первые три часа
     * суток колледжа уезжали в предыдущий день: вход в 01:00 по колледжу
     * рисовался вчерашним, а в «Входов сегодня» не попадал вовсе.
     *
     * Замерено 28.08.2026 на копии базы стенда: из 221 листа ответа
     * `executive()` правка сдвинула ровно три — два дня ряда и `entries_today`.
     */
    public function test_an_entry_after_college_midnight_belongs_to_the_new_day(): void
    {
        $today = CollegeTime::todayDate();
        $yesterday = Carbon::parse($today)->subDay()->toDateString();

        // 00:30 и 08:00 по колледжу одного и того же дня. Первое приходится на
        // UTC-вчера — в нём вся суть проверки; второе тем же днём в обоих
        // счислениях и служит контролем: оно двигаться не должно.
        AccessEvent::create([
            'direction' => AccessEvent::DIRECTION_IN,
            'result' => AccessEvent::RESULT_ALLOWED,
            'event_time' => CollegeTime::at($today, 0, 30),
        ]);
        AccessEvent::create([
            'direction' => AccessEvent::DIRECTION_IN,
            'result' => AccessEvent::RESULT_ALLOWED,
            'event_time' => CollegeTime::at($today, 8),
        ]);

        $data = app(DashboardAnalyticsService::class)->executive()['data'];
        $byDate = collect($data['charts']['access_7_days'])->pluck('value', 'date');

        $this->assertSame(2, (int) $byDate[$today], 'Оба входа сделаны в один день колледжа и обязаны стоять на нём.');
        $this->assertSame(0, (int) $byDate[$yesterday], 'Вход в первом часу ночи уехал во вчерашний день — ряд считает по UTC.');
        $this->assertSame(2, $data['kpi']['access']['entries_today'], 'Счётчик «сегодня» обязан считать те же сутки, что и ряд.');
    }

    /**
     * Ряды на колонках типа `date` перевода в пояс не терпят.
     *
     * У `schedule_lessons.lesson_date` и `applicant_applications.submitted_at`
     * часового пояса нет вовсе — проверено по схеме стенда 28.08.2026. Замер
     * там же, одним запросом:
     *
     * ```
     * date(DATE '2026-09-01' AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Moscow')
     *   → 2026-08-31
     * ```
     *
     * То есть занятие первого сентября уехало бы на августовский ряд.
     *
     * **Ловится это только на PostgreSQL, и знать об этом надо.** Проверено
     * внесением дефекта 28.08.2026 — `columnCarriesTime: true` у ряда занятий:
     * на PostgreSQL тест краснеет, на SQLite остаётся зелёным, потому что там
     * дата лежит строкой и сдвиг на минуты дня не меняет. Прогон по умолчанию
     * идёт на SQLite, так что **зелёный локальный прогон этот дефект не
     * опровергает**; ловит его прогон `phpunit.pgsql.xml`, который есть в CI.
     */
    public function test_a_date_column_is_not_shifted_into_the_college_zone(): void
    {
        $today = CollegeTime::todayDate();

        $this->createLesson($today, '09:00', '10:30');

        $series = app(DashboardAnalyticsService::class)->executive()['data']['charts']['lessons_7_days'];
        $byDate = collect($series)->pluck('value', 'date');

        $this->assertSame(1, (int) $byDate[$today]);
        $this->assertSame(0, (int) $byDate[Carbon::parse($today)->subDay()->toDateString()], 'Колонку `date` сдвинули в пояс, и занятие уехало на день назад.');
    }

    /**
     * Проверяется не общее число запросов, а то, ради чего правка делалась:
     * ряды считаются группировкой, а не днём за запрос. Общий счётчик для
     * этого не годится — он меняется от любой новой метрики на рабочем столе
     * и краснел бы не по делу.
     *
     * Замер на PostgreSQL 17: до правки открытие раздела стоило 101 запрос,
     * после — 83. Восемнадцать снятых — это три ряда по семь дней, ставшие
     * тремя запросами.
     */
    public function test_the_weekly_series_are_grouped_instead_of_counted_day_by_day(): void
    {
        $this->createLesson(now()->toDateString(), '09:00', '10:30');

        DB::flushQueryLog();
        DB::enableQueryLog();

        app(DashboardAnalyticsService::class)->executive();

        $log = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        // Признак ряда — группировка по дню, а не слова `count(*) as total`:
        // по ним сторож опознавал заодно и любую другую группировку на рабочем
        // столе. Свёртка счётчиков кадров такую же строку и написала, и сторож
        // покраснел на правке, которая ничего не сломала.
        $grouped = $log->filter(fn (string $sql) => str_contains($sql, 'group by "day"'))->count();

        $this->assertSame(
            3,
            $grouped,
            "Недельных рядов три, и каждый обязан стоить один запрос с группировкой; сгруппированных запросов найдено {$grouped}.",
        );
    }

    private function createLesson(string $date, string $startsAt, string $endsAt): ScheduleLesson
    {
        $this->context ??= [
            'group' => Group::create([
                'name' => 'ИСП-101',
                'specialty' => 'Инструментальное исполнительство',
                'course' => 1,
                'year_start' => 2026,
            ]),
            'teacher' => Teacher::create([
                'last_name' => 'Петров',
                'first_name' => 'Алексей',
                'is_active' => true,
            ]),
            'subject' => Subject::create([
                'name' => 'Сольфеджио',
                'code' => 'SOL-101',
            ]),
            'classroom' => Classroom::create([
                'number' => '201',
                'building' => 'Главный',
            ]),
        ];

        return ScheduleLesson::create([
            'group_id' => $this->context['group']->id,
            'teacher_id' => $this->context['teacher']->id,
            'subject_id' => $this->context['subject']->id,
            'classroom_id' => $this->context['classroom']->id,
            'lesson_date' => $date,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'lesson_type' => 'lesson',
            'topic' => 'Тестовая пара',
        ]);
    }
}
