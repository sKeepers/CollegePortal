<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FisExternalMapping extends Model
{
    protected $fillable = ['entity_type','entity_id','external_type','external_id','environment','metadata'];
    protected function casts(): array { return ['metadata' => 'array']; }
}
