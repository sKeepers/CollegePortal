<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UatTestResult extends Model
{
    use HasFactory;

    public const STATUSES = ['not_started', 'passed', 'failed', 'blocked', 'skipped'];

    protected $fillable = ['test_run_id', 'scenario_code', 'status', 'comment', 'actual_result', 'screenshot_path'];

    public function run(): BelongsTo { return $this->belongsTo(UatTestRun::class, 'test_run_id'); }
}
