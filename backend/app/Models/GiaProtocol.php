<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Протокол государственной итоговой аттестации.
 *
 * То, на что опираются приказ о выпуске и выгрузка в ФРДО: номер, дата, председатель и
 * решение по каждому выпускнику. До 24.08.2026 всего этого не было, а ГИА была строкой
 * экзамена с типом `gia`.
 *
 * Название группы и фамилии выпускников записаны рядом со ссылками намеренно: протокол —
 * документ, и читаться он обязан сам по себе, в том числе когда группы уже нет.
 */
class GiaProtocol extends Model
{
    use HasFactory;

    protected $fillable = [
        'number', 'protocol_date', 'academic_year', 'group_id', 'group_name',
        'education_program_id', 'chairman', 'chairman_position', 'secretary',
        'members', 'status', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'protocol_date' => 'date',
            'members' => 'array',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function educationProgram(): BelongsTo
    {
        return $this->belongsTo(EducationProgram::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(GiaProtocolDecision::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
