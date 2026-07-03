<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiplomaSupplement extends Model
{
    protected $fillable = ['diploma_id', 'series', 'number', 'issue_date', 'status', 'subjects', 'note'];

    protected function casts(): array
    {
        return ['issue_date' => 'date', 'subjects' => 'array'];
    }

    public function diploma(): BelongsTo { return $this->belongsTo(Diploma::class); }
}
