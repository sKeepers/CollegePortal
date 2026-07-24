<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UatFeedback extends Model
{
    use HasFactory;

    protected $table = 'uat_feedback';

    public const CATEGORIES = ['error', 'ux', 'suggestion', 'data', 'access'];
    public const SEVERITIES = ['critical', 'high', 'medium', 'low', 'ux'];
    public const STATUSES = ['new', 'confirmed', 'in_progress', 'needs_info', 'fixed', 'rejected', 'retest', 'closed'];

    protected $fillable = [
        'user_id', 'role_code', 'category', 'severity', 'title', 'description', 'expected_result', 'actual_result',
        'page_url', 'app_version', 'build_hash', 'environment', 'browser', 'user_agent', 'screenshot_path', 'status',
        'assigned_to', 'resolution', 'github_issue_number', 'github_issue_url', 'github_issue_status',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function statusHistory(): HasMany { return $this->hasMany(UatFeedbackStatusHistory::class, 'feedback_id')->latest(); }
    public function comments(): HasMany { return $this->hasMany(UatFeedbackComment::class, 'feedback_id')->latest(); }
}
