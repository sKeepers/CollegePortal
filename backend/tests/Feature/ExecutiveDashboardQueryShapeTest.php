<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeStatusPeriod;
use App\Models\Person;
use App\Services\DashboardAnalyticsService;
use App\Services\HrAbsenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Форма запросов рабочего стола директора.
 *
 * Раздел собирает сводку из трёх десятков показателей, и дважды уже
 * оказывалось, что показатели считаются повторно: сводка кадров спрашивала
 * одну и ту же выборку по счётчику на каждый статус, а блок «Требует
 * внимания» пересчитывал числа заявлений, посчитанные выше для сводки.
 * Замер на стенде: 81 запрос на открытие, из них 21 лишний.
 *
 * Сторожа здесь закреплены **по форме запроса, а не по их числу**: общий
 * счётчик краснел бы от любого нового показателя на рабочем столе, то есть не
 * по делу. И вместе с формой проверяется, что группировка считает те же
 * числа: свёртка запросов легко «ускоряет» экран, попутно меняя ответ.
 */
class ExecutiveDashboardQueryShapeTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_summary_counts_statuses_in_one_grouped_query(): void
    {
        $today = now()->toDateString();
        $this->period('vacation', $today, $today);
        $this->period('vacation', $today, null);
        $this->period('sick_leave', $today, $today);
        $this->period('business_trip', $today, $today);
        $this->period('dismissed', $today, null);
        // Отменённый период не считается ни в одном из показателей, и вчерашний
        // закрытый — тоже: он сегодня никого не задевает.
        $this->period('vacation', $today, $today, 'cancelled');
        $this->period('sick_leave', now()->subDays(3)->toDateString(), now()->subDays(2)->toDateString());

        $kpi = app(HrAbsenceService::class)->dashboardKpi();

        $this->assertSame(5, $kpi['absent_today'], 'Отсутствующие сегодня — все незакрытые периоды, включая увольнение.');
        $this->assertSame(2, $kpi['vacation_today']);
        $this->assertSame(1, $kpi['sick_leave_today']);
        $this->assertSame(1, $kpi['business_trip_today']);
    }

    public function test_no_status_is_counted_by_a_query_of_its_own(): void
    {
        $today = now()->toDateString();
        $this->period('vacation', $today, $today);

        $log = $this->queryLogOfExecutiveDashboard();

        $perStatus = $log->filter(fn (string $sql) => str_contains($sql, '"employee_status_periods"')
            && str_contains($sql, 'count(*)')
            // `"period_status" = ?` не подходит под этот образец: перед словом
            // стоит подчёркивание, а не кавычка.
            && str_contains($sql, '"status" = ?'));

        $this->assertCount(
            0,
            $perStatus,
            'Счётчик на каждый статус вернулся: сводка кадров обязана считать статусы одной группировкой. Найдено запросов: '.$perStatus->count(),
        );

        $grouped = $log->filter(fn (string $sql) => str_contains($sql, 'group by "status"'))->count();

        $this->assertSame(
            2,
            $grouped,
            "Группировок по статусу должно быть ровно две — сводка директора и сводка календаря кадров; найдено {$grouped}.",
        );
    }

    public function test_no_admissions_counter_is_asked_twice(): void
    {
        $queries = collect();
        DB::listen(function ($query) use ($queries) {
            $queries->push(['sql' => $query->sql, 'bindings' => $query->bindings]);
        });

        app(DashboardAnalyticsService::class)->executive();

        $admissions = $queries
            ->filter(fn (array $query) => str_contains($query['sql'], '"applicant_applications"')
                || str_contains($query['sql'], '"applicant_application_documents"'))
            ->map(fn (array $query) => $query['sql'].' :: '.json_encode($query['bindings'], JSON_UNESCAPED_UNICODE));

        $repeated = $admissions->countBy()->filter(fn (int $count) => $count > 1);

        $this->assertTrue(
            $repeated->isEmpty(),
            'Счётчик заявлений выполняется дважды за одно открытие раздела. Повторы: '
                .$repeated->keys()->map(fn (string $sql) => mb_substr($sql, 0, 120))->implode(' | '),
        );
    }

    /** @return \Illuminate\Support\Collection<int, string> */
    private function queryLogOfExecutiveDashboard(): \Illuminate\Support\Collection
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(DashboardAnalyticsService::class)->executive();

        $log = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        return $log;
    }

    private function period(string $status, string $dateFrom, ?string $dateTo, string $periodStatus = 'active'): EmployeeStatusPeriod
    {
        static $number = 0;
        $number++;

        $person = Person::create(['last_name' => 'Сотрудников', 'first_name' => 'Сотрудник '.$number, 'status' => 'active']);
        $employee = Employee::create([
            'person_id' => $person->id,
            'employee_number' => 'E-'.$number,
            'status' => 'active',
            'employment_type' => 'full_time',
            'hired_at' => '2026-01-01',
        ]);

        return EmployeeStatusPeriod::create([
            'employee_id' => $employee->id,
            'status' => $status,
            'period_status' => $periodStatus,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);
    }
}
