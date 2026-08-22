<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Отлучка с ведома: домой, на соревнования, в больницу.
 *
 * Без неё правило «вышел и не вернулся до утра» каждую пятницу собирало бы
 * половину этажа: уехавший домой на выходные неотличим от не вернувшегося.
 * Поэтому отлучка вычитается из расчёта **до** того, как отсутствие станет
 * отсутствием.
 */
class DormLeave extends Model
{
    protected $fillable = [
        'student_id',
        'starts_on',
        'ends_on',
        'reason',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
