<?php

namespace App\Services;

use App\Models\AccessEvent;
use App\Models\Employee;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Владельцы пропусков для пачки событий проходной — одним запросом на тип.
 *
 * `AccessEvent::owner` — не связь, а аксессор: `entity_type` и `entity_id`
 * указывают в три разные таблицы, и обычным `with()` их не подтянуть. Пока
 * каждый вызов ходил в базу сам, любой список событий делал запрос на строку.
 *
 * Список эвакуации на этом уже ловили — находка 13 аудита от 08.08.2026, 598
 * человек стоили 1129 запросов. Тогда починили только его, а отчёт проходной
 * остался: замер 16.08.2026 — одна страница отчёта, 1810 запросов. Поэтому
 * разбор вынесен сюда, а не переписан во второй раз на месте.
 */
class AccessEventOwners
{
    /**
     * Разложить владельцев по ключу «тип-идентификатор».
     *
     * @param  Collection<int, AccessEvent>  $events
     * @return array<string, Model>
     */
    public function map(Collection $events): array
    {
        $idsByType = $events
            ->groupBy('entity_type')
            ->map(fn (Collection $group): array => $group->pluck('entity_id')->filter()->unique()->values()->all());

        $sources = [
            'student' => fn (array $ids) => Student::query()->with('group')->whereIn('id', $ids)->get(),
            'teacher' => fn (array $ids) => Teacher::query()->whereIn('id', $ids)->get(),
            'employee' => fn (array $ids) => Employee::query()->with(['person', 'primaryDepartment'])->whereIn('id', $ids)->get(),
        ];

        $owners = [];

        foreach ($sources as $type => $load) {
            $ids = $idsByType->get($type, []);

            if ($ids === []) {
                continue;
            }

            foreach ($load($ids) as $model) {
                $owners[$type.'-'.$model->getKey()] = $model;
            }
        }

        return $owners;
    }

    /**
     * Проставить владельца каждому событию пачки.
     *
     * После этого `$event->owner` отдаёт уже найденное и в базу не ходит —
     * поэтому потребителям (ресурсам, выгрузкам) менять ничего не нужно.
     *
     * @param  Collection<int, AccessEvent>  $events
     * @return Collection<int, AccessEvent>
     */
    public function attach(Collection $events): Collection
    {
        $owners = $this->map($events);

        foreach ($events as $event) {
            $event->resolvedOwner = $owners[$event->entity_type.'-'.$event->entity_id] ?? null;
        }

        return $events;
    }
}
