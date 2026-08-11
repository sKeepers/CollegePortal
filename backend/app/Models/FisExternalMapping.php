<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FisExternalMapping extends Model
{
    /**
     * `scope` разводит несколько сопоставлений одной сущности. Пока применяется
     * к конкурсам ФИС: у программы их бывает несколько — бюджет и платное,
     * очное и заочное, — и различаются они формой обучения и источником
     * финансирования. Для остальных сопоставлений остаётся пустой строкой.
     */
    protected $fillable = ['entity_type','entity_id','external_type','external_id','environment','scope','metadata'];
    protected function casts(): array { return ['metadata' => 'array']; }
}
