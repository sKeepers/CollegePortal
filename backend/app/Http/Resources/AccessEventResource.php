<?php

namespace App\Http\Resources;

use App\Models\DigitalIdentity;
use App\Models\Person;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class AccessEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $owner = $this->owner;

        return [
            'id' => $this->id,
            'person_id' => $this->person_id,
            'digital_identity_id' => $this->digital_identity_id,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'direction' => $this->direction,
            'event_time' => $this->event_time?->toISOString(),
            'occurred_at' => ($this->occurred_at ?: $this->event_time)?->toISOString(),
            'access_point_id' => $this->access_point_id,
            'device_id' => $this->device_id,
            'access_point' => $this->accessPoint?->name ?? $this->access_point,
            'device_name' => $this->device?->name ?? $this->device_name,
            'result' => $this->result,
            'reason_code' => $this->reason_code,
            'reason' => $this->reason,
            'request_id' => $this->request_id,
            'owner' => $owner ? $this->ownerPayload($request, $owner) : null,
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

    private function ownerPayload(Request $request, Person|Student|Teacher $owner): array
    {
        if ($owner instanceof Person) {
            $student = $owner->primaryStudent;
            $teacher = $owner->primaryTeacher;
            $employee = $owner->primaryEmployee;
            $category = $student ? 'student' : ($teacher ? 'teacher' : 'employee');
            $photo = $owner->photo_path ?: ($student?->photo_path ?: $teacher?->photo_path);

            return [
                'id' => $owner->id,
                'display_name' => trim("{$owner->last_name} {$owner->first_name} {$owner->middle_name}"),
                'last_name' => $owner->last_name,
                'first_name' => $owner->first_name,
                'middle_name' => $owner->middle_name,
                'photo_url' => $this->photoUrl($request, $photo),
                'category' => $category,
                'entity_label' => match ($category) { 'student' => 'Студент', 'teacher' => 'Преподаватель', default => 'Сотрудник' },
                'group' => $student?->group ? ['id' => $student->group->id, 'name' => $student->group->name] : null,
                'department' => $teacher?->department ?: $employee?->primaryDepartment?->name,
            ];
        }

        return [
            'id' => $owner->id,
            'display_name' => trim("{$owner->last_name} {$owner->first_name} {$owner->middle_name}"),
            'last_name' => $owner->last_name,
            'first_name' => $owner->first_name,
            'middle_name' => $owner->middle_name,
            'photo_url' => $this->photoUrl($request, $owner->photo_path),
            'category' => $owner instanceof Student ? 'student' : 'teacher',
            'group' => $owner instanceof Student && $owner->relationLoaded('group') && $owner->group
                ? ['id' => $owner->group->id, 'name' => $owner->group->name]
                : null,
            'department' => $owner instanceof Teacher ? $owner->department : null,
            'entity_label' => $owner instanceof Student ? 'Студент' : 'Преподаватель',
        ];
    }

    private function photoUrl(Request $request, ?string $path): ?string
    {
        return $path ? $request->getSchemeAndHttpHost().Storage::disk('public')->url($path) : null;
    }
}
