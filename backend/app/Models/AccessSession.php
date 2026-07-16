<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessSession extends Model
{
    protected $fillable = ['person_id', 'entry_event_id', 'exit_event_id', 'started_at', 'ended_at', 'status'];
    protected function casts(): array { return ['started_at' => 'datetime', 'ended_at' => 'datetime']; }
}
