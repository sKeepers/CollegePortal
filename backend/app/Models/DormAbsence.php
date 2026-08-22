<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ночное отсутствие: за ночь человек не вернулся в общежитие.
 *
 * Называть это «не ночевал» нельзя, и в интерфейсе тоже. Проходная видит
 * только дверь: ушедший в окно или через чёрный ход неотличим от спящего.
 * Признак означает ровно «не входил до утра», не больше.
 *
 * Ночь называется по дате её начала: ночь с 3-го на 4-е — это 3-е.
 */
class DormAbsence extends Model
{
    protected $fillable = [
        'student_id',
        'night_of',
        'left_at',
        'returned_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'night_of' => 'date',
            'left_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
