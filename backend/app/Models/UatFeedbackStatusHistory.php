<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UatFeedbackStatusHistory extends Model
{
    use HasFactory;

    protected $table = 'uat_feedback_status_history';

    protected $fillable = [
        'feedback_id',
        'user_id',
        'old_status',
        'new_status',
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
