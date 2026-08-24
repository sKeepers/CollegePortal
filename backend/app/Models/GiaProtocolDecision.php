<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Решение комиссии по одному выпускнику.
 *
 * Строка, а не пересказ: из неё берут решение приказ о выпуске и выгрузка в ФРДО, и
 * диплом ссылается на неё вместо того, чтобы повторять её словами.
 */
class GiaProtocolDecision extends Model
{
    use HasFactory;

    public const RESULT_PASSED = 'passed';

    public const RESULT_FAILED = 'failed';

    public const RESULT_ABSENT = 'absent';

    protected $fillable = [
        'gia_protocol_id', 'student_id', 'student_name',
        'result', 'mark', 'qualification', 'note',
    ];

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(GiaProtocol::class, 'gia_protocol_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function diploma(): HasOne
    {
        return $this->hasOne(Diploma::class, 'gia_protocol_decision_id');
    }
}
