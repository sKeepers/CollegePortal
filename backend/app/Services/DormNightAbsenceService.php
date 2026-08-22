<?php

namespace App\Services;

use App\Models\AccessEvent;
use App\Models\DormAbsence;
use App\Models\DormLeave;
use App\Models\DormPlacement;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Расчёт ночных отсутствий по проходной.
 *
 * Правило одно и выбрано не случайно — «вышел и не вернулся до утра». Разбор
 * `DORM-001` проверял три правила на человеке, которого назвал владелец: вышел
 * покурить в 23:00, вернулся в 23:10.
 *
 * Правило «не входил после контрольного часа» ловит не отсутствие, а отсутствие
 * позднего входа: вернувшийся в 22:40 и легший спать попадает в список.
 * Правило «не было ни одного прохода за сутки» переворачивает смысл — не
 * выходивший из общежития оказывается самым отсутствующим. Годится только это.
 *
 * Два ограничения названы честно и здесь, и в интерфейсе:
 *
 * - **проходная видит только дверь.** Ушедший в окно неотличим от спящего, и
 *   признак означает «не входил до утра», а не «не ночевал»;
 * - **отлучка с ведома вычитается до расчёта.** Иначе каждую пятницу список
 *   собирал бы половину этажа: уехавший домой неотличим от не вернувшегося.
 *
 * Считаются только события точки прохода общежития. Возьми расчёт весь колледж
 * — и вошедший утром в учебный корпус закрыл бы себе ночь.
 */
class DormNightAbsenceService
{
    /**
     * Пересчитать ночь целиком.
     *
     * Ночь пересчитывается начисто: прежние строки за неё удаляются и пишутся
     * заново. Так задним числом добавленная отлучка убирает отсутствие, а не
     * оставляет его висеть — иначе комендант правил бы расчёт руками.
     *
     * @return array{counted: int, residents: int, skipped_by_leave: int}
     */
    public function recalculate(CarbonInterface|string $night): array
    {
        $night = Carbon::parse($night)->startOfDay();
        $buildingId = $this->dormBuildingId();

        if ($buildingId === null) {
            return ['counted' => 0, 'residents' => 0, 'skipped_by_leave' => 0];
        }

        $morning = $this->morningBoundary($night);
        $residents = $this->residentsOn($night);
        $onLeave = $this->studentsOnLeave($night);

        $rows = [];

        foreach ($residents as $studentId) {
            if ($onLeave->contains($studentId)) {
                continue;
            }

            $last = $this->lastDormEventBefore($studentId, $buildingId, $morning);

            if ($last === null || $last->direction !== AccessEvent::DIRECTION_OUT) {
                continue;
            }

            $rows[] = [
                'student_id' => $studentId,
                'night_of' => $night->toDateString(),
                'left_at' => $last->event_time,
                'returned_at' => $this->firstReturnAfter($studentId, $buildingId, $morning)?->event_time,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::transaction(function () use ($night, $rows): void {
            DormAbsence::query()->whereDate('night_of', $night->toDateString())->delete();

            if ($rows !== []) {
                // Пачкой и через insert: строк может быть на весь этаж, а
                // `updateOrCreate` в транзакции открывает точку сохранения на
                // каждую запись — на этом уже кончалась таблица блокировок.
                DormAbsence::query()->insert($rows);
            }
        });

        return [
            'counted' => count($rows),
            'residents' => $residents->count(),
            'skipped_by_leave' => $onLeave->intersect($residents)->count(),
        ];
    }

    public function dormBuildingId(): ?int
    {
        $id = (int) SettingService::value('dorm', 'building_id', 0);

        return $id > 0 ? $id : null;
    }

    private function morningBoundary(CarbonInterface $night): Carbon
    {
        $boundary = (string) SettingService::value('dorm', 'morning_boundary', '08:00');
        [$hour, $minute] = array_pad(explode(':', $boundary), 2, '0');

        // Утро следующего дня: ночь называется по дате своего начала.
        return Carbon::parse($night)->addDay()->setTime((int) $hour, (int) $minute);
    }

    /** Кто по документам живёт в общежитии в эту ночь. */
    private function residentsOn(CarbonInterface $night): \Illuminate\Support\Collection
    {
        return DormPlacement::query()
            ->whereDate('moved_in_at', '<=', $night->toDateString())
            ->where(function ($query) use ($night): void {
                $query->whereNull('moved_out_at')->orWhereDate('moved_out_at', '>', $night->toDateString());
            })
            ->pluck('student_id')
            ->unique()
            ->values();
    }

    private function studentsOnLeave(CarbonInterface $night): \Illuminate\Support\Collection
    {
        return DormLeave::query()
            ->whereDate('starts_on', '<=', $night->toDateString())
            ->whereDate('ends_on', '>=', $night->toDateString())
            ->pluck('student_id')
            ->unique()
            ->values();
    }

    private function lastDormEventBefore(int $studentId, int $buildingId, CarbonInterface $moment): ?AccessEvent
    {
        return $this->dormEvents($studentId, $buildingId)
            ->where('event_time', '<', $moment)
            ->orderByDesc('event_time')
            ->orderByDesc('id')
            ->first();
    }

    private function firstReturnAfter(int $studentId, int $buildingId, CarbonInterface $moment): ?AccessEvent
    {
        return $this->dormEvents($studentId, $buildingId)
            ->where('event_time', '>=', $moment)
            ->where('direction', AccessEvent::DIRECTION_IN)
            ->orderBy('event_time')
            ->first();
    }

    /**
     * Проходы этого студента через двери общежития.
     *
     * Только разрешённые: отказ — не проход, человек остался по ту же сторону
     * двери. Это же правило действует в расчёте направления и присутствия.
     */
    private function dormEvents(int $studentId, int $buildingId)
    {
        return AccessEvent::query()
            ->where('entity_type', 'student')
            ->where('entity_id', $studentId)
            ->where('result', AccessEvent::RESULT_ALLOWED)
            ->whereHas('accessPoint', fn ($point) => $point->where('building_id', $buildingId));
    }
}
