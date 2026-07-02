<?php

namespace App\Http\Resources;

use App\Models\DigitalIdentity;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccessEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $owner = $this->owner;

        return [
            'id' => $this->id,
            'digital_identity_id' => $this->digital_identity_id,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'direction' => $this->direction,
            'event_time' => $this->event_time?->toISOString(),
            'access_point' => $this->access_point,
            'device_name' => $this->device_name,
            'result' => $this->result,
            'reason' => $this->reason,
            'owner' => $owner ? $this->ownerPayload($owner) : null,
            'digital_identity' => $this->digitalIdentity ? [
                'id' => $this->digitalIdentity->id,
                'status' => $this->digitalIdentity->status,
                'issued_at' => $this->digitalIdentity->issued_at?->toISOString(),
                'expires_at' => $this->digitalIdentity->expires_at?->toISOString(),
                'revoked_at' => $this->digitalIdentity->revoked_at?->toISOString(),
            ] : null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'duplicate_ignored' => (bool) ($this->duplicate_ignored ?? false),
        ];
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
            'entity_label' => $this->entity_type === DigitalIdentity::ENTITY_STUDENT ? 'Студент' : 'Преподаватель',
        ];
    }
}
