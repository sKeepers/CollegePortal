<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FrdoValidationErrorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'frdo_package_id' => $this->frdo_package_id,
            'frdo_record_id' => $this->frdo_record_id,
            'field' => $this->field,
            'message' => $this->message,
            'severity' => $this->severity,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
