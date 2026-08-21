<?php

namespace App\Services;

use App\Models\DigitalIdentity;
use App\Models\Employee;
use App\Models\Person;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Support\Str;

/**
 * Пропуск есть у каждого человека.
 *
 * Решение владельца 21.08.2026. До сих пор пропуск появлялся побочно: при
 * заведении учётной записи или отдельным действием кадров. Кого не завели через
 * эти два пути, тот оставался без пропуска — и узнавал об этом у турникета.
 *
 * Пропуск выдаётся **человеку, а не карточке**: у преподавателя, который ещё и
 * сотрудник, человек один, и пропуск ему нужен один. Поэтому проверка идёт по
 * `person_id`, а карточка выбирается только чтобы было к чему привязать запись.
 *
 * Транзакции здесь намеренно нет. Выдача вызывается из наблюдателя при создании
 * карточки, то есть внутри чужой транзакции, а вложенная транзакция на
 * PostgreSQL открывает точку сохранения — на массовой загрузке контингента их
 * набрались бы сотни, и прогон упирался бы в таблицу блокировок. Отзывать здесь
 * нечего: выдаём только тем, у кого действующего пропуска нет, одной вставкой.
 */
class DigitalPassIssueService
{
    /**
     * Карточка, к которой привязывается пропуск, выбирается в этом порядке.
     *
     * Сотрудник впереди преподавателя: на проходной человек прежде всего
     * работник колледжа, а преподавание — вид его работы. Студент последний
     * просто потому, что у студента других карточек не бывает.
     */
    private const OWNER_ORDER = [
        DigitalIdentity::ENTITY_EMPLOYEE => Employee::class,
        DigitalIdentity::ENTITY_TEACHER => Teacher::class,
        DigitalIdentity::ENTITY_STUDENT => Student::class,
    ];

    public function ensureForPerson(?int $personId): ?DigitalIdentity
    {
        if ($personId === null) {
            return null;
        }

        if ($this->hasLivePass($personId)) {
            return null;
        }

        $owner = $this->ownerFor($personId);

        if ($owner === null) {
            return null;
        }

        [$entityType, $entityId] = $owner;

        $identity = DigitalIdentity::create([
            'person_id' => $personId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'token' => (string) Str::uuid(),
            'status' => DigitalIdentity::STATUS_ACTIVE,
            'issued_at' => now(),
            'expires_at' => null,
        ]);

        AuditLogService::log('digital_identity', 'issued_automatically', $identity, null, [
            'person_id' => $personId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ], personId: $personId);

        return $identity;
    }

    /** Сколько людей остались без пропуска. */
    public function peopleWithoutPass(): int
    {
        return Person::query()
            ->whereDoesntHave('digitalIdentities', fn ($query) => $query->whereIn('status', [
                DigitalIdentity::STATUS_ACTIVE,
                DigitalIdentity::STATUS_SUSPENDED,
            ]))
            ->where(fn ($query) => $query->has('students')->orHas('teachers')->orHas('employees'))
            ->count();
    }

    private function hasLivePass(int $personId): bool
    {
        return DigitalIdentity::query()
            ->where('person_id', $personId)
            ->whereIn('status', [DigitalIdentity::STATUS_ACTIVE, DigitalIdentity::STATUS_SUSPENDED])
            ->exists();
    }

    /** @return array{0: string, 1: int}|null */
    private function ownerFor(int $personId): ?array
    {
        foreach (self::OWNER_ORDER as $entityType => $class) {
            $id = $class::query()->where('person_id', $personId)->value('id');

            if ($id !== null) {
                return [$entityType, (int) $id];
            }
        }

        return null;
    }
}
