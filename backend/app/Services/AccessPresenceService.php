<?php

namespace App\Services;

use App\Models\AccessEvent;
use App\Models\Building;
use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Единственное место, где решается, кто сейчас в здании.
 *
 * Правило одно на всю систему: берется последнее разрешенное событие человека
 * за текущий день, и если оно «вход» — человек внутри. По дню, а не за все время:
 * иначе не отсканировавший выход остается внутри навсегда. Не по фильтрам отчета:
 * иначе выборка за прошлый месяц показывает людей «в здании» того месяца.
 *
 * Сводка отчетов, KPI рабочего стола и поименный список эвакуации обязаны
 * показывать одно и то же число, поэтому считают его здесь, а не у себя.
 */
class AccessPresenceService
{
    public const UNASSIGNED_BUILDING_LABEL = 'Точка прохода не указана';

    /**
     * Последнее сегодняшнее разрешенное событие каждого пропуска.
     */
    public function latestAllowedToday(): Collection
    {
        return AccessEvent::query()
            ->whereDate('event_time', Carbon::now()->toDateString())
            ->where('result', AccessEvent::RESULT_ALLOWED)
            ->whereNotNull('digital_identity_id')
            ->with('accessPoint.building')
            ->orderByDesc('event_time')
            ->orderByDesc('id')
            ->get()
            ->unique('digital_identity_id');
    }

    /**
     * Те, чье последнее сегодняшнее событие — вход.
     */
    public function insideNowEvents(): Collection
    {
        return $this->latestAllowedToday()
            ->where('direction', AccessEvent::DIRECTION_IN)
            ->values();
    }

    public function insideNowCount(): int
    {
        return $this->insideNowEvents()->count();
    }

    /**
     * Поименный список по каждому зданию — muster report на случай эвакуации.
     *
     * Здания возвращаются все, включая пустые: на эвакуации важно видеть, что
     * корпус проверен и пуст, а не то, что он пропал из списка. Люди, вошедшие
     * через точку вне справочника, собираются в отдельную группу, иначе они
     * потерялись бы совсем.
     *
     * @return list<array<string, mixed>>
     */
    public function musterByBuilding(): array
    {
        $events = $this->insideNowEvents();
        $grouped = $events->groupBy(fn (AccessEvent $event) => $event->accessPoint?->building_id ?? 0);

        $groups = Building::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Building $building) => [
                'building_id' => $building->id,
                'building_name' => $building->name,
                'building_code' => $building->code,
                'address' => $building->address,
                'people_count' => $grouped->get($building->id, collect())->count(),
                'people' => $this->people($grouped->get($building->id, collect())),
            ])
            ->values()
            ->all();

        $unassigned = $grouped->get(0, collect());

        if ($unassigned->isNotEmpty()) {
            $groups[] = [
                'building_id' => null,
                'building_name' => self::UNASSIGNED_BUILDING_LABEL,
                'building_code' => null,
                'address' => null,
                'people_count' => $unassigned->count(),
                'people' => $this->people($unassigned),
            ];
        }

        return $groups;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function people(Collection $events): array
    {
        return $events
            ->map(function (AccessEvent $event): array {
                $owner = $event->owner;
                $person = $owner instanceof Employee ? $owner->person : $owner;
                $name = trim(implode(' ', array_filter([
                    $person?->last_name,
                    $person?->first_name,
                    $person?->middle_name,
                ])));

                return [
                    'access_event_id' => $event->id,
                    'entity_type' => $event->entity_type,
                    'entity_id' => $event->entity_id,
                    'full_name' => $name !== '' ? $name : 'Пропуск без карточки',
                    'entity_label' => match ($event->entity_type) {
                        'student' => 'Студент',
                        'teacher' => 'Преподаватель',
                        'employee' => 'Сотрудник',
                        default => 'Неизвестно',
                    },
                    'group' => $owner !== null && method_exists($owner, 'group') && $owner->relationLoaded('group')
                        ? $owner->group?->name
                        : null,
                    'phone' => $person?->phone,
                    'access_point' => $event->accessPoint?->name ?? $event->access_point,
                    'entered_at' => $event->event_time?->toISOString(),
                ];
            })
            ->sortBy('full_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }
}
