<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FisOutboundPackageEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'event_type' => $this->event_type, 'status' => $this->status, 'request_id' => $this->request_id, 'metadata' => $this->metadata, 'user_id' => $this->user_id, 'created_at' => $this->created_at?->toISOString()];
    }
}
