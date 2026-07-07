<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportJob extends Model
{
    protected $fillable = [
        'user_id',
        'data_type',
        'mode',
        'status',
        'original_filename',
        'stored_path',
        'headers',
        'mapping',
        'preview_rows',
        'validation_errors',
        'result',
        'total_rows',
        'created_count',
        'updated_count',
        'skipped_count',
        'error_count',
    ];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'mapping' => 'array',
            'preview_rows' => 'array',
            'validation_errors' => 'array',
            'result' => 'array',
            'total_rows' => 'integer',
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'skipped_count' => 'integer',
            'error_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
