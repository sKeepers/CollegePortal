<?php

namespace App\Services\Trash;

use App\Models\DeletionRequest;
use App\Models\DigitalIdentity;
use App\Models\Employee;
use App\Models\Person;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Удаление в два шага.
 *
 * Роль, нашедшая ошибочно заведённую карточку, оставляет заявку с причиной.
 * Администратор проверяет и решает. Одобрение не стирает запись: она уходит в
 * корзину, откуда её можно вернуть, а вычищает корзину администратор отдельно
 * и вручную — срока хранения владелец не задавал.
 */
class DeletionRequestService
{
    /**
     * Что можно пометить на удаление. Начали с карточек людей: именно про них
     * сказано «карточка заведена ошибочно». Справочники, группы и расписание —
     * следующим шагом, когда владелец скажет.
     *
     * @var array<string, class-string<Model>>
     */
    public const SUBJECTS = [
        'student' => Student::class,
        'teacher' => Teacher::class,
        'employee' => Employee::class,
        'person' => Person::class,
    ];

    /**
     * Что уходит вместе с карточкой человека.
     *
     * Своей жизни без человека у этих записей нет: карточка студента без
     * человека — это «?» вместо ФИО, учётная запись без человека — вход в
     * пустой кабинет, пропуск — право прохода у того, кого больше нет. Поэтому
     * они снимаются вместе с ним, а не оставляются висеть: внешние ключи здесь
     * обнуляющие, и молчаливое сиротство — самый вероятный исход.
     *
     * @var array<string, string>
     */
    public const CASCADE = [
        'students' => 'Карточка студента',
        'teachers' => 'Карточка преподавателя',
        'employees' => 'Карточка сотрудника',
        'users' => 'Учётная запись',
        'digitalIdentities' => 'Электронный пропуск',
    ];

    /**
     * Что удалять вместе с человеком нельзя.
     *
     * Это записи приёмной комиссии и выпуска: за ними стоят поданные документы,
     * загруженные файлы и выданные дипломы. Их удаление — отдельное решение и
     * отдельный порядок, а не побочный итог уборки в разделе «Люди». Пока они
     * есть, карточка не помечается, и в отказе прямо сказано, что мешает.
     *
     * @var array<string, string>
     */
    public const BLOCKERS = [
        'applicants' => 'Карточка абитуриента',
        'applicantApplications' => 'Заявление абитуриента',
        'admissionIdentityDocuments' => 'Документ, удостоверяющий личность',
        'admissionEducationDocuments' => 'Документ об образовании',
        'graduates' => 'Запись выпускника',
    ];

    /**
     * Что будет удалено вместе с карточкой и что этому мешает.
     *
     * Спрашивается до пометки: владелец просил не удалять молча, а показывать
     * связанные записи и предлагать их к удалению вместе.
     *
     * @return array{cascade: list<array<string, mixed>>, blockers: list<array<string, mixed>>}
     */
    public function dependents(string $type, int $id): array
    {
        $subject = $this->find($type, $id);

        if (! $subject instanceof Person) {
            return ['cascade' => [], 'blockers' => []];
        }

        $collect = function (array $map) use ($subject): array {
            $rows = [];
            foreach ($map as $relation => $label) {
                $items = $subject->{$relation}()->get();
                if ($items->isEmpty()) {
                    continue;
                }
                $rows[] = [
                    'relation' => $relation,
                    'label' => $label,
                    'count' => $items->count(),
                    'ids' => $items->pluck($items->first()->getKeyName())->all(),
                ];
            }

            return $rows;
        };

        return ['cascade' => $collect(self::CASCADE), 'blockers' => $collect(self::BLOCKERS)];
    }

    public function create(string $type, int $id, string $reason, User $actor): DeletionRequest
    {
        $subject = $this->find($type, $id);

        if ($this->pendingFor($subject)) {
            throw ValidationException::withMessages([
                'subject_id' => 'На эту карточку уже есть заявка на удаление, ожидающая решения.',
            ]);
        }

        $dependents = $this->dependents($type, $id);

        if ($dependents['blockers'] !== []) {
            $what = collect($dependents['blockers'])
                ->map(fn (array $row): string => mb_strtolower($row['label']).' — '.$row['count'])
                ->implode('; ');

            throw ValidationException::withMessages([
                'subject_id' => 'Карточку нельзя пометить на удаление, пока за человеком числится: '
                    .$what.'. Эти записи удаляются отдельно, в своём разделе.',
            ]);
        }

        $request = DeletionRequest::create([
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'subject_label' => $this->label($subject),
            // Состав каскада запоминается на момент пометки: администратор
            // должен решать, глядя на тот же список, что видел заявитель.
            'cascade' => $dependents['cascade'] ?: null,
            'reason' => $reason,
            'status' => DeletionRequest::STATUS_PENDING,
            'requested_by' => $actor->id,
        ]);

        AuditLogService::log('trash', 'requested', $request, null, [
            'subject_type' => $type,
            'subject_id' => $subject->getKey(),
        ]);

        return $request;
    }

    public function approve(DeletionRequest $request, User $actor): DeletionRequest
    {
        $this->assertPending($request);

        DB::transaction(function () use ($request, $actor): void {
            // Карточку могли удалить и раньше — тогда заявка всё равно
            // закрывается, но второй раз ничего не делается.
            $subject = $request->subject;
            $applied = $subject instanceof Person ? $this->takeDownDependents($subject) : null;
            $subject?->delete();

            $request->update([
                'status' => DeletionRequest::STATUS_APPROVED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                // Записываем то, что действительно снято, а не то, что
                // планировалось: между пометкой и решением состав мог измениться,
                // а возвращать из корзины надо ровно снятое.
                'cascade' => $applied ?: $request->cascade,
            ]);
        });

        AuditLogService::log('trash', 'approved', $request, null, [
            'subject_type' => $request->subject_type,
            'subject_id' => $request->subject_id,
        ]);

        return $request->fresh();
    }

    public function reject(DeletionRequest $request, User $actor, ?string $comment = null): DeletionRequest
    {
        $this->assertPending($request);

        $request->update([
            'status' => DeletionRequest::STATUS_REJECTED,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'review_comment' => $comment,
        ]);

        AuditLogService::log('trash', 'rejected', $request, null, ['comment' => $comment]);

        return $request->fresh();
    }

    /**
     * Содержимое корзины по всем поддержанным видам карточек.
     *
     * @return list<array<string, mixed>>
     */
    public function trash(): array
    {
        $items = [];

        foreach (self::SUBJECTS as $type => $class) {
            foreach ($class::onlyTrashed()->orderByDesc('deleted_at')->get() as $model) {
                $request = DeletionRequest::query()
                    ->where('subject_type', $class)
                    ->where('subject_id', $model->getKey())
                    ->where('status', DeletionRequest::STATUS_APPROVED)
                    ->latest('reviewed_at')
                    ->first();

                $items[] = [
                    'type' => $type,
                    'id' => $model->getKey(),
                    'label' => $this->label($model),
                    'deleted_at' => $model->deleted_at?->toISOString(),
                    'reason' => $request?->reason,
                    'requested_by' => $request?->requestedBy?->name,
                    'reviewed_by' => $request?->reviewedBy?->name,
                ];
            }
        }

        return $items;
    }

    public function restore(string $type, int $id): Model
    {
        $model = $this->findTrashed($type, $id);
        $model->restore();

        if ($model instanceof Person) {
            $this->bringBackDependents($model);
        }

        AuditLogService::log('trash', 'restored', $model, null, ['type' => $type, 'id' => $id]);

        return $model;
    }

    /** Окончательное удаление. Дальше корзины возврата нет. */
    public function purge(string $type, int $id): void
    {
        $model = $this->findTrashed($type, $id);
        $label = $this->label($model);

        DB::transaction(function () use ($model): void {
            if ($model instanceof Person) {
                // Порядок важен: пропуска и учётные записи ссылаются на человека,
                // а профильные карточки — ещё и друг на друга через него. Сначала
                // снимается всё зависимое, и только потом сам человек.
                $model->digitalIdentities()->delete();
                $model->users()->delete();

                foreach ([$model->students(), $model->teachers(), $model->employees()] as $relation) {
                    $relation->withTrashed()->get()->each->forceDelete();
                }
            }

            $model->forceDelete();
        });

        AuditLogService::log('trash', 'purged', null, null, ['type' => $type, 'id' => $id, 'label' => $label]);
    }

    /**
     * Снять зависимые записи вместе с человеком — обратимо.
     *
     * Карточки уходят в ту же корзину, учётная запись выключается, пропуск
     * отзывается. Ничего не стирается: пока человек в корзине, решение можно
     * отменить целиком. Возвращается состав снятого — по нему и восстанавливаем.
     *
     * @return list<array<string, mixed>>
     */
    private function takeDownDependents(Person $person): array
    {
        $applied = [];

        foreach (['students' => Student::class, 'teachers' => Teacher::class, 'employees' => Employee::class] as $relation => $class) {
            $ids = $person->{$relation}()->pluck((new $class)->getKeyName())->all();
            if ($ids !== []) {
                $person->{$relation}()->get()->each->delete();
                $applied[] = ['relation' => $relation, 'label' => self::CASCADE[$relation], 'count' => count($ids), 'ids' => $ids];
            }
        }

        // Вход выключается, а не удаляется: удалённый человек не должен работать
        // в портале, но и учётную запись терять до очистки корзины незачем.
        $userIds = $person->users()->where('is_active', true)->pluck('id')->all();
        if ($userIds !== []) {
            User::query()->whereIn('id', $userIds)->update(['is_active' => false]);
            $applied[] = ['relation' => 'users', 'label' => self::CASCADE['users'], 'count' => count($userIds), 'ids' => $userIds];
        }

        // Отзываем только действующие пропуска: отозванный раньше и по другой
        // причине не должен ожить при возврате из корзины.
        $identityIds = $person->digitalIdentities()->where('status', DigitalIdentity::STATUS_ACTIVE)->pluck('id')->all();
        if ($identityIds !== []) {
            DigitalIdentity::query()->whereIn('id', $identityIds)->update(['status' => DigitalIdentity::STATUS_REVOKED]);
            $applied[] = ['relation' => 'digitalIdentities', 'label' => self::CASCADE['digitalIdentities'], 'count' => count($identityIds), 'ids' => $identityIds];
        }

        return $applied;
    }

    /** Вернуть то, что ушло вместе с человеком. */
    private function bringBackDependents(Person $person): void
    {
        $request = DeletionRequest::query()
            ->where('subject_type', Person::class)
            ->where('subject_id', $person->getKey())
            ->where('status', DeletionRequest::STATUS_APPROVED)
            ->latest('reviewed_at')
            ->first();

        foreach ($request?->cascade ?? [] as $row) {
            $ids = $row['ids'] ?? [];
            if ($ids === []) {
                continue;
            }

            match ($row['relation'] ?? '') {
                'students' => Student::withTrashed()->whereIn('id', $ids)->get()->each->restore(),
                'teachers' => Teacher::withTrashed()->whereIn('id', $ids)->get()->each->restore(),
                'employees' => Employee::withTrashed()->whereIn('id', $ids)->get()->each->restore(),
                'users' => User::query()->whereIn('id', $ids)->update(['is_active' => true]),
                'digitalIdentities' => DigitalIdentity::query()->whereIn('id', $ids)->update(['status' => DigitalIdentity::STATUS_ACTIVE]),
                default => null,
            };
        }
    }

    private function pendingFor(Model $subject): bool
    {
        return DeletionRequest::query()
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())
            ->pending()
            ->exists();
    }

    private function assertPending(DeletionRequest $request): void
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'status' => 'Заявка уже рассмотрена.',
            ]);
        }
    }

    private function find(string $type, int $id): Model
    {
        return $this->modelClass($type)::query()->findOrFail($id);
    }

    private function findTrashed(string $type, int $id): Model
    {
        return $this->modelClass($type)::onlyTrashed()->findOrFail($id);
    }

    /** @return class-string<Model> */
    private function modelClass(string $type): string
    {
        return self::SUBJECTS[$type] ?? throw ValidationException::withMessages([
            'subject_type' => 'Пометить на удаление можно карточку студента, преподавателя, сотрудника или человека.',
        ]);
    }

    private function label(Model $model): string
    {
        $name = trim(implode(' ', array_filter([
            $model->last_name ?? null,
            $model->first_name ?? null,
            $model->middle_name ?? null,
        ])));

        return $name !== '' ? $name : class_basename($model).' #'.$model->getKey();
    }
}
