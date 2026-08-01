<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessDevice extends Model
{
    public const TYPE_MOBILE_CAMERA = 'mobile_camera';
    public const TYPE_HID_SCANNER = 'hid_scanner';
    public const TYPE_MANUAL = 'manual';

    protected $fillable = ['access_point_id', 'type', 'name', 'identifier', 'active', 'last_seen_at'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'last_seen_at' => 'datetime'];
    }

    public function accessPoint(): BelongsTo
    {
        return $this->belongsTo(AccessPoint::class);
    }
}
