<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccessEventResource;
use App\Models\AccessEvent;
use App\Models\Employee;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\AccessPresenceService;
use App\Services\AccessReportService;
use App\Support\Csv\CsvExport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccessReportController extends Controller
{
    public function __construct(
        private readonly AccessPresenceService $presence,
        private readonly AccessReportService $report,
    ) {
    }

    public function summary(Request $request): array
    {
        return [
            'data' => $this->report->summary($request) + ['inside_now' => $this->presence->insideNowCount()],
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
        if ($request->string('export')->toString() === 'csv') {
            return $this->exportCsv($this->report->stream($request));
        }

        ['rows' => $rows, 'total' => $total, 'limit' => $limit] = $this->report->screenEvents($request);

        // Общее число едет рядом со списком: экран показывает последние события,
        // и без этого числа обрезанный список читается как полный.
        return AccessEventResource::collection($rows)->additional([
            'meta' => ['total' => $total, 'limit' => $limit, 'truncated' => $total > $limit],
        ]);
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

    /**
     * Выгрузка отдаёт всё, что подошло под фильтры, а не первую страницу:
     * до 10.08.2026 сюда приходила уже обрезанная тысяча, и файл молча
     * оказывался неполным. Строки идут курсором и в память не собираются.
     *
     * @param \Illuminate\Support\LazyCollection<int, AccessEvent> $events
     */
    private function exportCsv($events): StreamedResponse
    {
        $filename = 'access-events-'.now()->format('Ymd-His').'.csv';

        // Дата и время разведены по столбцам: в одной ячейке период не свести
        // сводной таблицей, а именно этим выгрузку и разбирают.
        return CsvExport::download($filename, ['Дата', 'Время', 'ФИО', 'Тип', 'Группа или подразделение', 'Корпус', 'Точка доступа', 'Направление', 'Результат', 'Причина', 'Устройство'], function (callable $row) use ($events): void {
            foreach ($events as $event) {
                $owner = $event->owner;
                $person = $owner instanceof Employee ? $owner->person : $owner;
                $name = trim(implode(' ', array_filter([$person?->last_name, $person?->first_name, $person?->middle_name]))) ?: 'Неизвестный пропуск';
                $row([
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
                ]);
            }
        });
    }
}
