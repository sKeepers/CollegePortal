<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UatFeedbackComment extends Model
{
    use HasFactory;

    public const TYPES = ['admin', 'developer', 'tester'];

    protected $table = 'uat_feedback_comments';

    protected $fillable = [
        'feedback_id',
        'user_id',
        'type',
        'comment',
    ];

    public function feedback(): BelongsTo
    {
        return $this->belongsTo(UatFeedback::class, 'feedback_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
