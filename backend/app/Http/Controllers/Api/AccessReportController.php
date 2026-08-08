<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccessEventResource;
use App\Models\AccessEvent;
use App\Models\Employee;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\AccessPresenceService;
use App\Services\AttendanceAnalysisService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccessReportController extends Controller
{
    public function __construct(
        private readonly AccessPresenceService $presence,
        private readonly AttendanceAnalysisService $attendance,
    ) {
    }

    public function summary(Request $request): array
    {
        $events = $this->filteredEvents($request);
        $todayEvents = $events->filter(fn (AccessEvent $event) => $event->event_time?->isToday());
        $allowedEvents = $events->where('result', AccessEvent::RESULT_ALLOWED);

        return [
            'data' => [
                'today_events' => $todayEvents->count(),
                'entries' => $allowedEvents->where('direction', AccessEvent::DIRECTION_IN)->count(),
                'exits' => $allowedEvents->where('direction', AccessEvent::DIRECTION_OUT)->count(),
                'denied' => $events->where('result', AccessEvent::RESULT_DENIED)->count(),
                'inside_now' => $this->presence->insideNowCount(),
            ],
        ];
    }

    /**
     * Поименный список находящихся в здании — на случай эвакуации. Открывается
     * без фильтров и без параметров: в этот момент никто не будет настраивать
     * выборку. Пустые корпуса остаются в списке, чтобы было видно, что корпус
     * проверен, а не потерян.
     */
    public function muster(): array
    {
        $groups = $this->presence->musterByBuilding();

        return [
            'data' => [
                'generated_at' => Carbon::now()->toISOString(),
                'inside_now' => $this->presence->insideNowCount(),
                'buildings' => $groups,
            ],
        ];
    }

    public function events(Request $request): AnonymousResourceCollection|StreamedResponse
    {
        $events = $this->filteredEvents($request)
            ->sortByDesc('event_time')
            ->values();

        if ($request->string('export')->toString() === 'csv') {
            return $this->exportCsv($events);
        }

        return AccessEventResource::collection($events->take(200));
    }

    /**
     * «Только опоздавшие» считается разбором посещаемости, а не заново: опоздание
     * там уже определено по расписанию человека, и второй расчет неизбежно начал
     * бы расходиться с журналом. Сотрудников это не охватывает: их опоздание
     * зависит от рабочего графика, который с порогом опоздания пока не связан.
     *
     * @return list<string> ключи вида «student-17»
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
                    $keys[] = $row['entity_type'].'-'.$row['entity_id'];
                }
            }
        }

        return $keys;
    }

    private function filteredEvents(Request $request)
    {
        $query = AccessEvent::query()
            ->with(['digitalIdentity', 'accessPoint.building'])
            ->when($request->string('entity_type')->toString(), fn ($query, string $type) => $query->where('entity_type', $type))
            ->when($request->string('result')->toString(), fn ($query, string $result) => $query->where('result', $result))
            ->when($request->string('date')->toString(), function ($query, string $date): void {
                $query->whereBetween('event_time', [Carbon::parse($date)->startOfDay(), Carbon::parse($date)->endOfDay()]);
            })
            ->when($request->string('date_from')->toString(), fn ($query, string $date) => $query->where('event_time', '>=', Carbon::parse($date)->startOfDay()))
            ->when($request->string('date_to')->toString(), fn ($query, string $date) => $query->where('event_time', '<=', Carbon::parse($date)->endOfDay()));

        $events = $query->orderByDesc('event_time')->limit(1000)->get();

        if ($request->boolean('only_late')) {
            $lateKeys = $this->lateKeys($request);
            $events = $events->filter(
                fn (AccessEvent $event): bool => in_array($event->entity_type.'-'.$event->entity_id, $lateKeys, true)
            )->values();
        }

        $search = mb_strtolower(trim($request->string('search')->toString()));

        if ($search === '') {
            return $events;
        }

        return $events->filter(function (AccessEvent $event) use ($search): bool {
            $owner = $event->owner;
            $person = $owner instanceof Employee ? $owner->person : $owner;
            $name = mb_strtolower(implode(' ', array_filter([
                $person?->last_name,
                $person?->first_name,
                $person?->middle_name,
            ])));

            return str_contains($name, $search);
        })->values();
    }

    /**
     * У студента это группа, у преподавателя и сотрудника — подразделение.
     * В отчете колонка одна: разбирают его по человеку, а не по типу профиля.
     */
    private function unitName(?Model $owner): ?string
    {
        return match (true) {
            $owner instanceof Student => $owner->group?->name,
            $owner instanceof Teacher => $owner->department,
            $owner instanceof Employee => $owner->primaryDepartment?->name,
            default => null,
        };
    }

    private function exportCsv($events): StreamedResponse
    {
        $filename = 'access-events-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($events): void {
            $output = fopen('php://output', 'w');
            // Дата и время разведены по столбцам: в одной ячейке период не свести
            // сводной таблицей, а именно этим выгрузку и разбирают.
            fputcsv($output, ['Дата', 'Время', 'ФИО', 'Тип', 'Группа или подразделение', 'Корпус', 'Точка доступа', 'Направление', 'Результат', 'Причина', 'Устройство'], ';');

            foreach ($events as $event) {
                $owner = $event->owner;
                $person = $owner instanceof Employee ? $owner->person : $owner;
                $name = trim(implode(' ', array_filter([$person?->last_name, $person?->first_name, $person?->middle_name]))) ?: 'Неизвестный пропуск';
                fputcsv($output, [
                    $event->event_time?->format('d.m.Y'),
                    $event->event_time?->format('H:i'),
                    $name,
                    match ($event->entity_type) {
                        'student' => 'Студент',
                        'teacher' => 'Преподаватель',
                        'employee' => 'Сотрудник',
                        default => 'Неизвестно',
                    },
                    $this->unitName($owner),
                    $event->accessPoint?->building?->name,
                    $event->accessPoint?->name ?? $event->access_point,
                    $event->direction === AccessEvent::DIRECTION_OUT ? 'Выход' : 'Вход',
                    $event->result === AccessEvent::RESULT_ALLOWED ? 'Разрешено' : 'Отказано',
                    $event->reason,
                    $event->device_name,
                ], ';');
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
