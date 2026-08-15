<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Кто чью группу курирует.
 *
 * Правило области: право открывает раздел, а чьи данные видно — решает
 * эндпоинт. Для куратора весь вопрос сводится к одной связи: `groups.curator_id`
 * указывает на карточку преподавателя, а карточка — на учётную запись. Связь
 * посчитана здесь один раз, чтобы журнал, мобильный кабинет и успеваемость не
 * выводили её каждый по-своему: разойдясь однажды, они откроют куратору чужую
 * группу в одном месте и закроют свою в другом.
 *
 * **Карточек преподавателя у учётной записи может быть несколько.** На стенде у
 * `teacher@local` их две, и `hasOne` берёт первую — пустую. Поэтому группы
 * ищутся по всем карточкам пользователя: иначе куратор с двумя карточками не
 * видит собственной группы, и выглядит это как поломка кабинета, а не как
 * задвоенная карточка.
 *
 * Считается запросом на каждый вызов, но не чаще одного раза на пользователя за
 * запрос: смена куратора у группы обязана действовать сразу, а не после
 * перевхода.
 */
class CuratorScopeService
{
    /** @var array<int, Collection<int, int>> */
    private array $teacherIds = [];

    /** @var array<int, Collection<int, int>> */
    private array $curatedGroupIds = [];

    /**
     * Карточки преподавателя этой учётной записи.
     *
     * @return Collection<int, int>
     */
    public function teacherIds(User $user): Collection
    {
        return $this->teacherIds[$user->id] ??= Teacher::query()
            ->where('user_id', $user->id)
            ->pluck('id');
    }

    /**
     * Группы, где этот человек — куратор.
     *
     * @return Collection<int, int>
     */
    public function curatedGroupIds(User $user): Collection
    {
        return $this->curatedGroupIds[$user->id] ??= (function () use ($user): Collection {
            $teacherIds = $this->teacherIds($user);

            if ($teacherIds->isEmpty()) {
                return collect();
            }

            return Group::query()->whereIn('curator_id', $teacherIds)->pluck('id');
        })();
    }

    /** Курирует ли этот человек эту группу. */
    public function curates(User $user, Group|int $group): bool
    {
        $groupId = $group instanceof Group ? (int) $group->id : (int) $group;

        return $this->curatedGroupIds($user)->contains($groupId);
    }
}
