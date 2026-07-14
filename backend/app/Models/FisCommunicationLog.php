<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FisCommunicationLog extends Model
{
    protected $fillable = [
        'occurred_at',
        'request_id',
        'direction',
        'transport',
        'method',
        'duration_ms',
        'status',
        'http_code',
        'soap_fault_code',
        'soap_fault_message',
        'error_code',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
