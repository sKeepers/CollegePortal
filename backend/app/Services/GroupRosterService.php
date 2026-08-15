<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Состав группы: кто в ней сейчас учится.
 *
 * Правило «действующий студент» — не в архиве и не отчислен — нужно и списку
 * группы, и расчёту успеваемости. Считать его в двух местах нельзя: средний
 * балл окажется посчитан по одному составу, а показан рядом со списком
 * другого, и разойдутся они молча.
 *
 * Пустой `status` считается действующим намеренно: у карточек, заведённых до
 * появления статусов, он не заполнен, и отбрасывать их значило бы потерять
 * старые группы целиком.
 */
class GroupRosterService
{
    /** @return Collection<int, Student> */
    public function active(Group|int $group): Collection
    {
        return $this->query($group)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    /** @return Builder<Student> */
    public function query(Group|int $group): Builder
    {
        $groupId = $group instanceof Group ? (int) $group->id : (int) $group;

        return Student::query()
            ->where('group_id', $groupId)
            ->whereNull('archived_at')
            ->where(function (Builder $inner): void {
                $inner->whereNull('status')->orWhereNotIn('status', ['archived', 'expelled']);
            });
    }
}
