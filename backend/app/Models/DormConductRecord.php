<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Провинность — рабочая заметка воспитателя, а не объявленное взыскание.
 *
 * Объявленное взыскание оформляется приказом и живёт в другом месте. Отсюда
 * три правила, названные до первой строки кода, а не после:
 *
 * - **студент своих провинностей не видит.** Решение владельца от 22.08.2026.
 *   Как только запись станет видимой, ею перестанут пользоваться честно;
 * - **не удаляется, но гаснет через год** — перестаёт учитываться в активных и
 *   уходит в историю;
 * - **исправляется дополнением.** Автор правит запись в течение суток, дальше
 *   только отдельная запись со ссылкой на первую: история не переписывается
 *   задним числом, но ошибка исправима.
 */
class DormConductRecord extends Model
{
    /** Сколько у автора есть на правку своей записи. */
    public const EDIT_WINDOW_HOURS = 24;

    protected $fillable = [
        'student_id',
        'parent_id',
        'happened_on',
        'summary',
        'description',
        'expires_on',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'happened_on' => 'date',
            'expires_on' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Запись ещё учитывается: срок не вышел. */
    public function isActive(): bool
    {
        return $this->expires_on === null || $this->expires_on->isFuture();
    }
}
