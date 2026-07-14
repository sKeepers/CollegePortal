<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'entity_type' => $this->entity_type,
            'numbering_pattern' => $this->numbering_pattern,
            'requires_registration' => $this->requires_registration,
            'requires_qr' => $this->requires_qr,
            'is_active' => $this->is_active,
        ];
    }
}
