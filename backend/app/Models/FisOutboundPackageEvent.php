<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FisOutboundPackageEvent extends Model
{
    protected $fillable = ['fis_outbound_package_id','event_type','status','request_id','metadata','user_id'];

    protected function casts(): array { return ['metadata' => 'array']; }

    public function package(): BelongsTo { return $this->belongsTo(FisOutboundPackage::class, 'fis_outbound_package_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
