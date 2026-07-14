<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FisCommunicationLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'occurred_at' => $this->occurred_at?->toISOString(),
            'request_id' => $this->request_id,
            'direction' => $this->direction,
            'transport' => $this->transport,
            'method' => $this->method,
            'duration_ms' => $this->duration_ms,
            'status' => $this->status,
            'http_code' => $this->http_code,
            'soap_fault_code' => $this->soap_fault_code,
            'soap_fault_hash' => $this->metadata['soap_fault_hash'] ?? null,
            'error_code' => $this->error_code,
            'metadata' => $this->metadata,
        ];
    }
}
