<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UatFeedback extends Model
{
    use HasFactory;

    protected $table = 'uat_feedback';

    public const CATEGORIES = ['error', 'ux', 'suggestion', 'data', 'access'];
    public const SEVERITIES = ['critical', 'high', 'medium', 'low', 'ux'];
    public const STATUSES = ['new', 'confirmed', 'in_progress', 'fixed', 'rejected', 'retest', 'closed'];

    protected $fillable = [
        'user_id', 'role_code', 'category', 'severity', 'title', 'description', 'expected_result', 'actual_result',
        'page_url', 'app_version', 'build_hash', 'environment', 'screenshot_path', 'status', 'assigned_to', 'resolution',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
}
