<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FisValidationError extends Model
{
    protected $fillable = ['fis_package_id', 'fis_record_id', 'field', 'message', 'severity'];

    public function package(): BelongsTo { return $this->belongsTo(FisPackage::class, 'fis_package_id'); }
    public function record(): BelongsTo { return $this->belongsTo(FisRecord::class, 'fis_record_id'); }
}
