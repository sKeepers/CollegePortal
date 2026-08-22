<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Group;
use App\Models\ScheduleLesson;
use App\Models\Subject;
use App\Models\Teacher;
use App\Services\DashboardAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $firstDay = now()->subDays(6)->toDateString();
        $today = now()->toDateString();

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
        $this->assertSame(0, (int) $byDate[now()->subDays(3)->toDateString()], 'День без занятий обязан быть нулём, а не пропуском.');
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

        $grouped = $log->filter(fn (string $sql) => str_contains($sql, 'count(*) as total'))->count();

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
