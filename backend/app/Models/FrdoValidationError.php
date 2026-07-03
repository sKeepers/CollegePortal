<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FrdoValidationError extends Model
{
    protected $fillable = ['frdo_package_id', 'frdo_record_id', 'field', 'message', 'severity'];

    public function package(): BelongsTo { return $this->belongsTo(FrdoPackage::class, 'frdo_package_id'); }
    public function record(): BelongsTo { return $this->belongsTo(FrdoRecord::class, 'frdo_record_id'); }
}
