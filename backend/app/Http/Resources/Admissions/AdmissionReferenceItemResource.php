<?php

namespace App\Http\Resources\Admissions;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource элемента справочника приемной комиссии.
 */
class AdmissionReferenceItemResource extends JsonResource
{
    /**
     * Формирует безопасное read-only представление элемента admissions-справочника.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'metadata' => $this->metadata ?? [],
        ];
    }
}
