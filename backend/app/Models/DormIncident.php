<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Происшествие в общежитии: драка, потоп, кража.
 *
 * Записывается по горячим следам, поэтому полей ровно столько, сколько успеешь
 * заполнить в тот момент: когда, что, кто участвовал, что сделали. Подробности
 * дописываются потом — но запись должна появиться сразу, иначе не появится.
 *
 * Видят и ведут **обе роли**: комендант живёт этим по должности, заместитель по
 * воспитательной работе разбирает последствия. Это единственная часть
 * общежития, общая для двух контуров.
 */
class DormIncident extends Model
{
    protected $fillable = [
        'building_id',
        'dorm_room_id',
        'happened_at',
        'summary',
        'description',
        'measures',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return ['happened_at' => 'datetime'];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(DormRoom::class, 'dorm_room_id');
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'dorm_incident_participants')
            ->withPivot('role')
            ->withTimestamps();
    }
}
