<?php

namespace App\Models;

use App\Support\Graduation\BlankNumberInDiploma;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Diploma extends Model
{
    protected $fillable = ['graduate_id', 'series', 'number', 'registration_number', 'issue_date', 'qualification', 'gia_decision', 'status', 'note'];

    protected function casts(): array
    {
        return ['issue_date' => 'date'];
    }

    /**
     * Номер бланка сверяется с учётом на каждой записи, а не в контроллере.
     *
     * Путей записи у диплома уже три — карточка выпускника, загрузка из файла и
     * само закрепление бланка, — и правило, поставленное на одном из них,
     * обходится двумя остальными.
     */
    protected static function booted(): void
    {
        static::saving(fn (Diploma $diploma) => BlankNumberInDiploma::agree($diploma));
    }

    public function graduate(): BelongsTo { return $this->belongsTo(Graduate::class); }
    public function supplement(): HasOne { return $this->hasOne(DiplomaSupplement::class); }
}
