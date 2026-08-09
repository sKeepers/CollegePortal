<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FisOutboundPackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'package_type' => $this->package_type, 'external_campaign_id' => $this->external_campaign_id, 'admission_year' => $this->admission_year, 'source_entity_type' => $this->source_entity_type, 'source_entity_id' => $this->source_entity_id, 'schema_version' => $this->schema_version, 'environment' => $this->environment, 'status' => $this->status, 'payload_sha256' => $this->payload_sha256, 'package_id' => $this->package_id, 'request_id' => $this->request_id, 'created_by' => $this->created_by, 'validated_at' => $this->validated_at?->toISOString(), 'sent_at' => $this->sent_at?->toISOString(), 'accepted_at' => $this->accepted_at?->toISOString(), 'completed_at' => $this->completed_at?->toISOString(), 'failed_at' => $this->failed_at?->toISOString(), 'retry_count' => $this->retry_count, 'last_error_code' => $this->last_error_code, 'last_error_message' => $this->last_error_message, 'response_metadata' => $this->response_metadata, 'events_count' => $this->events_count ?? null, 'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString()];
    }
}
