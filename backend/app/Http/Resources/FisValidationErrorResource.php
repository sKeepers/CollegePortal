<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FisValidationErrorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'fis_package_id' => $this->fis_package_id, 'fis_record_id' => $this->fis_record_id, 'field' => $this->field, 'message' => $this->message, 'severity' => $this->severity, 'created_at' => $this->created_at?->toISOString()];
    }
}
