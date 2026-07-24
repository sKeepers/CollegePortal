<?php

namespace App\Http\Resources\Admissions;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource каталога справочников приемной комиссии.
 */
class AdmissionReferenceCatalogResource extends JsonResource
{
    /**
     * Формирует read-only контракт каталога справочников приемной комиссии.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'is_system' => $this->is_system,
            'items' => AdmissionReferenceItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
