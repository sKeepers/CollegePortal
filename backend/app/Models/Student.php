<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    // Удаление карточки не окончательное: она уходит в корзину, откуда её
    // возвращает или вычищает администратор.
    use SoftDeletes;

    protected $fillable = [
        'person_id',
        'user_id',
        'group_id',
        'course',
        'last_name',
        'first_name',
        'middle_name',
        'birth_date',
        'phone',
        'email',
        'snils',
        'address',
        'passport_series',
        'passport_number',
        'passport_issue_date',
        'passport_issued_by',
        'passport_department_code',
        'photo_path',
        'status',
        'is_resident',
        'enrollment_date',
        'enrollment_order_number',
        'enrollment_order_date',
        // Номер личного дела, он же номер зачётной книжки, он же номер в
        // алфавитном классификаторе бумажных списков — это один номер.
        'personal_file_number',
        // Буква закрепляется за делом при заведении и при смене фамилии не меняется.
        'personal_file_letter',
        'education_form',
        'funding_form',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'enrollment_date' => 'date',
            'passport_issue_date' => 'date',
            'enrollment_order_date' => 'date',
            'course' => 'integer',
            'is_resident' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }
}
