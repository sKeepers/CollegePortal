<?php

namespace App\Models;

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

    public function graduate(): BelongsTo { return $this->belongsTo(Graduate::class); }
    public function supplement(): HasOne { return $this->hasOne(DiplomaSupplement::class); }
}
