<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FisOutboundPackage extends Model
{
    public const STATUSES = ['draft','generated','validation_failed','validated','queued','sending','sent','accepted','processing','completed','rejected','failed','cancelled'];

    protected $fillable = ['package_type','external_campaign_id','source_entity_type','source_entity_id','schema_version','environment','status','payload_path','payload_sha256','package_id','request_id','created_by','validated_at','sent_at','accepted_at','completed_at','failed_at','retry_count','last_error_code','last_error_message','response_metadata'];

    protected function casts(): array
    {
        return ['response_metadata' => 'array', 'validated_at' => 'datetime', 'sent_at' => 'datetime', 'accepted_at' => 'datetime', 'completed_at' => 'datetime', 'failed_at' => 'datetime', 'retry_count' => 'integer'];
    }

    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function events(): HasMany { return $this->hasMany(FisOutboundPackageEvent::class); }
}
