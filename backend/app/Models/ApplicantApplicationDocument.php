<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicantApplicationDocument extends Model
{
    protected $fillable = [
        'applicant_application_id',
        'type',
        'title',
        'is_received',
        'received_at',
        'number',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'is_received' => 'boolean',
            'received_at' => 'date',
        ];
    }

    public function applicantApplication(): BelongsTo
    {
        return $this->belongsTo(ApplicantApplication::class);
    }
}
