<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicantApplicationEvent extends Model
{
    protected $fillable = [
        'applicant_application_id',
        'type',
        'title',
        'description',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function applicantApplication(): BelongsTo
    {
        return $this->belongsTo(ApplicantApplication::class);
    }
}
