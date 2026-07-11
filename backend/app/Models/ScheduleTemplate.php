<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScheduleTemplate extends Model
{
    protected $fillable = ['name', 'academic_year', 'semester', 'valid_from', 'valid_to', 'group_id', 'week_type', 'status', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['semester' => 'integer', 'valid_from' => 'date', 'valid_to' => 'date'];
    }

    public function group(): BelongsTo { return $this->belongsTo(Group::class); }
    public function entries(): HasMany { return $this->hasMany(ScheduleTemplateEntry::class); }
}
