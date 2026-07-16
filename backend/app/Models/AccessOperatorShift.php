<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessOperatorShift extends Model
{
    protected $fillable = ['operator_id', 'access_point_id', 'started_at', 'ended_at', 'status'];
    protected function casts(): array { return ['started_at' => 'datetime', 'ended_at' => 'datetime']; }
}
