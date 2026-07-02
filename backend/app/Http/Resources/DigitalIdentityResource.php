<?php

namespace App\Http\Resources;

use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DigitalIdentityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $owner = $this->owner;

        return [
            'id' => $this->id,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'token' => $this->token,
            'status' => $this->effectiveStatus(),
            'issued_at' => $this->issued_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'revoked_at' => $this->revoked_at?->toISOString(),
            'owner' => $owner ? $this->ownerPayload($owner) : null,
            'qr_url' => url("/api/digital-identities/{$this->id}/qr"),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function effectiveStatus(): string
    {
        if ($this->status === 'active' && $this->expires_at !== null && $this->expires_at->isPast()) {
            return 'expired';
        }

        return $this->status;
    }

    private function ownerPayload(Student|Teacher $owner): array
    {
        return [
            'id' => $owner->id,
            'last_name' => $owner->last_name,
            'first_name' => $owner->first_name,
            'middle_name' => $owner->middle_name,
            'phone' => $owner->phone,
            'email' => $owner->email,
            'group' => $owner instanceof Student && $owner->relationLoaded('group') && $owner->group
                ? ['id' => $owner->group->id, 'name' => $owner->group->name]
                : null,
            'position' => $owner instanceof Teacher ? $owner->position : null,
            'department' => $owner instanceof Teacher ? $owner->department : null,
        ];
    }
}
