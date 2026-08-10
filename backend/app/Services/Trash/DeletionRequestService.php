<?php

namespace App\Services\Trash;

use App\Models\DeletionRequest;
use App\Models\Employee;
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
    ];

    public function create(string $type, int $id, string $reason, User $actor): DeletionRequest
    {
        $subject = $this->find($type, $id);

        if ($this->pendingFor($subject)) {
            throw ValidationException::withMessages([
                'subject_id' => 'На эту карточку уже есть заявка на удаление, ожидающая решения.',
            ]);
        }

        $request = DeletionRequest::create([
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'subject_label' => $this->label($subject),
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
            $request->subject?->delete();

            $request->update([
                'status' => DeletionRequest::STATUS_APPROVED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
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

        AuditLogService::log('trash', 'restored', $model, null, ['type' => $type, 'id' => $id]);

        return $model;
    }

    /** Окончательное удаление. Дальше корзины возврата нет. */
    public function purge(string $type, int $id): void
    {
        $model = $this->findTrashed($type, $id);
        $label = $this->label($model);

        $model->forceDelete();

        AuditLogService::log('trash', 'purged', null, null, ['type' => $type, 'id' => $id, 'label' => $label]);
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
            'subject_type' => 'Пометить на удаление можно карточку студента, преподавателя или сотрудника.',
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
