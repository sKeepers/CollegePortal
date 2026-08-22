<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Заселение: кто, в какую комнату и с какого числа.
 *
 * Переселение — это не правка строки, а закрытие прежнего заселения и открытие
 * нового. История переселений нужна заместителю по воспитательной работе, и
 * правкой на месте она бы стёрлась.
 */
class DormPlacement extends Model
{
    protected $fillable = [
        'dorm_room_id',
        'student_id',
        'moved_in_at',
        'moved_out_at',
        'basis',
        'note',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'moved_in_at' => 'date',
            'moved_out_at' => 'date',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(DormRoom::class, 'dorm_room_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Человек всё ещё живёт по этому заселению. */
    public function isOpen(): bool
    {
        return $this->moved_out_at === null;
    }
}
