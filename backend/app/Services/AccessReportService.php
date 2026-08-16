<?php

namespace App\Services;

use App\Models\AccessEvent;
use App\Models\Employee;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * Отбор событий проходной для отчета.
 *
 * До 10.08.2026 отбор жил в контроллере и начинался с `limit(1000)`. Всё
 * остальное — счетчики сводки, фильтр «только опоздавшие», поиск по фамилии и
 * выгрузка в CSV — считалось уже по этой тысяче. За две недели демонстрационных
 * данных сводка показывала ровно 1000 событий за день там, где их 1269, а
 * выгрузка молча отдавала обрезанный файл: ни подписи, ни признака обрезки.
 *
 * Теперь ограничение осталось ровно в одном месте — в списке на экране, где оно
 * и нужно, и рядом с ним едет общее число, чтобы обрезка была видна. Всё
 * остальное работает по базе целиком.
 */
class AccessReportService
{
    /** Сколько строк показывает экран. Выгрузка и счетчики этим не ограничены. */
    public const SCREEN_LIMIT = 200;

    public function __construct(
        private readonly AttendanceAnalysisService $attendance,
        private readonly AccessEventOwners $owners,
    ) {
    }

    /**
     * Сводка считается агрегатом на стороне базы: раньше строки выбирались в
     * память и пересчитывались коллекцией, поэтому счетчики упирались в тот же
     * предел, что и список.
     *
     * @return array<string, int>
     */
    public function summary(Request $request): array
    {
        $row = $this->query($request)
            ->selectRaw(
                'count(*) as total_events'
                .', sum(case when event_time >= ? and event_time <= ? then 1 else 0 end) as today_events'
                .', sum(case when result = ? and direction = ? then 1 else 0 end) as entries'
                .', sum(case when result = ? and direction = ? then 1 else 0 end) as exits'
                .', sum(case when result = ? then 1 else 0 end) as denied',
                [
                    Carbon::today()->startOfDay(),
                    Carbon::today()->endOfDay(),
                    AccessEvent::RESULT_ALLOWED,
                    AccessEvent::DIRECTION_IN,
                    AccessEvent::RESULT_ALLOWED,
                    AccessEvent::DIRECTION_OUT,
                    AccessEvent::RESULT_DENIED,
                ],
            )
            ->first();

        return [
            'total_events' => (int) ($row->total_events ?? 0),
            'today_events' => (int) ($row->today_events ?? 0),
            'entries' => (int) ($row->entries ?? 0),
            'exits' => (int) ($row->exits ?? 0),
            'denied' => (int) ($row->denied ?? 0),
        ];
    }

    /**
     * Страница списка: последние события и общее их число рядом. Число нужно
     * именно здесь — без него человек читает обрезанный список как полный.
     *
     * @return array{rows: \Illuminate\Support\Collection<int, AccessEvent>, total: int, limit: int}
     */
    public function screenEvents(Request $request): array
    {
        $limit = (int) ($request->integer('limit') ?: self::SCREEN_LIMIT);
        $limit = max(1, min($limit, 1000));

        return [
            // Владельцы пропусков — одним запросом на тип, а не по одному на
            // строку: `AccessEvent::owner` это аксессор, и без предварительного
            // разбора страница отчёта стоила 1810 запросов (замер 16.08.2026).
            'rows' => $this->owners->attach(
                $this->query($request)
                    ->with(['digitalIdentity', 'accessPoint.building'])
                    ->orderByDesc('event_time')
                    ->limit($limit)
                    ->get()
            ),
            'total' => $this->query($request)->count(),
            'limit' => $limit,
        ];
    }

    /**
     * Выгрузка идет курсором: файл за месяц — это десятки тысяч строк, и
     * собирать их в память ради CSV незачем.
     *
     * @return LazyCollection<int, AccessEvent>
     */
    public function stream(Request $request): LazyCollection
    {
        // Владельцы разбираются пачками по тысяче: курсор остаётся курсором, в
        // память попадает пачка, а не файл целиком, и запросов выходит три на
        // тысячу строк вместо тысячи.
        return $this->query($request)
            ->with(['digitalIdentity', 'accessPoint.building'])
            ->orderByDesc('event_time')
            ->lazy()
            ->chunk(1000)
            ->flatMap(fn (LazyCollection $chunk): Collection => $this->owners->attach($chunk->collect()));
    }

    /**
     * Запрос со всеми фильтрами, применёнными до какого-либо ограничения.
     *
     * @return Builder<AccessEvent>
     */
    private function query(Request $request): Builder
    {
        return AccessEvent::query()
            ->when($request->string('entity_type')->toString(), fn (Builder $query, string $type) => $query->where('entity_type', $type))
            ->when($request->string('result')->toString(), fn (Builder $query, string $result) => $query->where('result', $result))
            ->when($request->string('date')->toString(), function (Builder $query, string $date): void {
                $query->whereBetween('event_time', [Carbon::parse($date)->startOfDay(), Carbon::parse($date)->endOfDay()]);
            })
            ->when($request->string('date_from')->toString(), fn (Builder $query, string $date) => $query->where('event_time', '>=', Carbon::parse($date)->startOfDay()))
            ->when($request->string('date_to')->toString(), fn (Builder $query, string $date) => $query->where('event_time', '<=', Carbon::parse($date)->endOfDay()))
            ->when($request->boolean('only_late'), fn (Builder $query) => $this->restrictToKeys($query, $this->lateKeys($request)))
            ->when(trim($request->string('search')->toString()) !== '', fn (Builder $query) => $this->restrictToKeys($query, $this->searchKeys(trim($request->string('search')->toString()))));
    }

    /**
     * Ограничение по списку «тип-идентификатор». Пустой список означает, что
     * под условие не подошёл никто, и запрос обязан вернуть ноль строк, а не
     * молча потерять фильтр.
     *
     * @param array<string, list<int>> $keys
     * @param Builder<AccessEvent> $query
     */
    private function restrictToKeys(Builder $query, array $keys): void
    {
        if ($keys === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $query) use ($keys): void {
            foreach ($keys as $type => $ids) {
                $query->orWhere(fn (Builder $inner) => $inner->where('entity_type', $type)->whereIn('entity_id', $ids));
            }
        });
    }

    /**
     * «Только опоздавшие» считается разбором посещаемости, а не заново: опоздание
     * там уже определено по расписанию человека, и второй расчет неизбежно начал
     * бы расходиться с журналом. Сотрудников это не охватывает: их опоздание
     * зависит от рабочего графика, который с порогом опоздания пока не связан.
     *
     * @return array<string, list<int>>
     */
    private function lateKeys(Request $request): array
    {
        $filters = [
            'date_from' => $request->string('date_from')->toString() ?: $request->string('date')->toString(),
            'date_to' => $request->string('date_to')->toString() ?: $request->string('date')->toString(),
        ];

        $requested = $request->string('entity_type')->toString();
        $types = $requested !== '' ? [$requested] : ['student', 'teacher'];

        $keys = [];
        foreach (array_intersect($types, ['student', 'teacher']) as $type) {
            foreach ($this->attendance->history($filters + ['type' => $type])['data'] as $row) {
                // Именно late_count, а не сводный статус: сводный ставит
                // «незакрытый вход» выше опоздания, и опоздавший, забывший
                // отсканировать выход, из отчета об опозданиях выпадал бы.
                if (($row['late_count'] ?? 0) > 0) {
                    $keys[$row['entity_type']][] = (int) $row['entity_id'];
                }
            }
        }

        return $keys;
    }

    /**
     * Поиск по фамилии тоже уходит в базу: раньше он перебирал уже выбранную
     * тысячу строк, и человек, чьи проходы в неё не попали, просто не находился.
     *
     * Идентификаторы собираются по профилям, а не джойном к событиям: связь
     * события с человеком полиморфная, и одного джойна для трёх типов профиля
     * всё равно не получится.
     *
     * @return array<string, list<int>>
     */
    private function searchKeys(string $search): array
    {
        $operator = AccessEvent::query()->getModel()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $needle = '%'.$search.'%';

        $byName = fn ($query) => $query->where(fn ($inner) => $inner
            ->where('last_name', $operator, $needle)
            ->orWhere('first_name', $operator, $needle)
            ->orWhere('middle_name', $operator, $needle));

        $keys = [
            'student' => Student::query()->where($byName)->pluck('id')->all(),
            'teacher' => Teacher::query()->where($byName)->pluck('id')->all(),
            'employee' => Employee::query()->whereHas('person', $byName)->pluck('id')->all(),
        ];

        return array_filter($keys, static fn (array $ids): bool => $ids !== []);
    }
}
