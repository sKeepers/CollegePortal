<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessAuditEvent extends Model
{
    protected $fillable = ['user_id', 'person_id', 'access_event_id', 'action', 'request_id', 'metadata'];
    protected function casts(): array { return ['metadata' => 'array']; }
}
