<?php

namespace App\Http\Resources\Admissions;

use App\Http\Resources\PersonResource;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Read-only API Resource foundation-профиля абитуриента.
 */
class ApplicantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'person_id' => $this->person_id,
            'person' => new PersonResource($this->whenLoaded('person')),
            'source_id' => $this->source_id,
            'source' => new AdmissionReferenceItemResource($this->whenLoaded('source')),
            'status_id' => $this->status_id,
            'status' => new AdmissionReferenceItemResource($this->whenLoaded('status')),
            'first_contact_at' => $this->first_contact_at?->toISOString(),
            'responsible_user_id' => $this->responsible_user_id,
            'responsible_user' => new UserResource($this->whenLoaded('responsibleUser')),
            'notes' => $this->notes,
            'archived_at' => $this->archived_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
