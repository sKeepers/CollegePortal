<?php

namespace App\Models\Admissions;

use App\Models\Person;
use App\Models\ReferenceItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Foundation-профиль абитуриента, связанный с общей Person.
 */
class Applicant extends Model
{
    protected $fillable = [
        'uuid',
        'person_id',
        'source_id',
        'status_id',
        'first_contact_at',
        'responsible_user_id',
        'notes',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'first_contact_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'source_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'status_id');
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }
}
