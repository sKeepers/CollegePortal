<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccessEventResource;
use App\Models\AccessEvent;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccessReportController extends Controller
{
    public function summary(Request $request): array
    {
        $events = $this->filteredEvents($request);
        $todayEvents = $events->filter(fn (AccessEvent $event) => $event->event_time?->isToday());
        $allowedEvents = $events->where('result', AccessEvent::RESULT_ALLOWED);
        $latestAllowedByIdentity = $allowedEvents
            ->filter(fn (AccessEvent $event) => $event->digital_identity_id !== null)
            ->sortByDesc('event_time')
            ->unique('digital_identity_id');

        return [
            'data' => [
                'today_events' => $todayEvents->count(),
                'entries' => $allowedEvents->where('direction', AccessEvent::DIRECTION_IN)->count(),
                'exits' => $allowedEvents->where('direction', AccessEvent::DIRECTION_OUT)->count(),
                'denied' => $events->where('result', AccessEvent::RESULT_DENIED)->count(),
                'inside_now' => $latestAllowedByIdentity->where('direction', AccessEvent::DIRECTION_IN)->count(),
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

    private function filteredEvents(Request $request)
    {
        $query = AccessEvent::query()
            ->with('digitalIdentity')
            ->when($request->string('entity_type')->toString(), fn ($query, string $type) => $query->where('entity_type', $type))
            ->when($request->string('result')->toString(), fn ($query, string $result) => $query->where('result', $result))
            ->when($request->string('date')->toString(), function ($query, string $date): void {
                $query->whereBetween('event_time', [Carbon::parse($date)->startOfDay(), Carbon::parse($date)->endOfDay()]);
            })
            ->when($request->string('date_from')->toString(), fn ($query, string $date) => $query->where('event_time', '>=', Carbon::parse($date)->startOfDay()))
            ->when($request->string('date_to')->toString(), fn ($query, string $date) => $query->where('event_time', '<=', Carbon::parse($date)->endOfDay()));

        $events = $query->orderByDesc('event_time')->limit(1000)->get();
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

    private function exportCsv($events): StreamedResponse
    {
        $filename = 'access-events-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($events): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Дата и время', 'ФИО', 'Тип', 'Направление', 'Результат', 'Причина', 'Точка доступа', 'Устройство'], ';');

            foreach ($events as $event) {
                $owner = $event->owner;
                $person = $owner instanceof Employee ? $owner->person : $owner;
                $name = trim(implode(' ', array_filter([$person?->last_name, $person?->first_name, $person?->middle_name]))) ?: 'Неизвестный пропуск';
                fputcsv($output, [
                    $event->event_time?->format('d.m.Y H:i'),
                    $name,
                    match ($event->entity_type) {
                        'student' => 'Студент',
                        'teacher' => 'Преподаватель',
                        'employee' => 'Сотрудник',
                        default => 'Неизвестно',
                    },
                    $event->direction === AccessEvent::DIRECTION_OUT ? 'Выход' : 'Вход',
                    $event->result === AccessEvent::RESULT_ALLOWED ? 'Разрешено' : 'Отказано',
                    $event->reason,
                    $event->access_point,
                    $event->device_name,
                ], ';');
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
