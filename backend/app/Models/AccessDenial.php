<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessDenial extends Model
{
    protected $fillable = ['access_event_id', 'person_id', 'reason_code', 'reason', 'context'];
    protected function casts(): array { return ['context' => 'array']; }
}
