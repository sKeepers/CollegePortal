<?php

namespace App\Http\Resources;

use App\Models\Student;
use App\Models\Teacher;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class DigitalIdentityResource extends JsonResource
{
    /**
     * Ключ `owner` отдаётся **всегда**, даже когда владельца нет.
     *
     * На этом держится различие двух разных случаев, которые экран до 24.08.2026
     * смешивал в один: `owner: null` значит «владельца нет», а отсутствие ключа
     * — «связь не запрашивали». Смешавшись, они давали подпись «Преподаватель
     * #77», и владелец портала читал её как преподавателя с номером. Убирать
     * ключ ради экономии ответа нельзя: отсутствие снова станет неотличимо от
     * умолчания.
     */
    public function toArray(Request $request): array
    {
        $owner = $this->owner;

        return [
            'id' => $this->id,
            'person_id' => $this->person_id,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'token' => Gate::allows('permission', 'digitalpasses.manage') ? $this->token : null,
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

    private function ownerPayload(Student|Teacher|Employee $owner): array
    {
        $person = $owner instanceof Employee ? $owner->person : $owner;

        return [
            'id' => $owner->id,
            'last_name' => $person?->last_name,
            'first_name' => $person?->first_name,
            'middle_name' => $person?->middle_name,
            'phone' => $person?->phone,
            'email' => $person?->email,
            'group' => $owner instanceof Student && $owner->relationLoaded('group') && $owner->group
                ? ['id' => $owner->group->id, 'name' => $owner->group->name]
                : null,
            'position' => $owner instanceof Teacher ? $owner->position : null,
            'department' => $owner instanceof Teacher ? $owner->department : null,
            'employee_number' => $owner instanceof Employee ? $owner->employee_number : null,
            'primary_position' => $owner instanceof Employee && $owner->primaryPosition
                ? ['id' => $owner->primaryPosition->id, 'name' => $owner->primaryPosition->name]
                : null,
            'primary_department' => $owner instanceof Employee && $owner->primaryDepartment
                ? ['id' => $owner->primaryDepartment->id, 'name' => $owner->primaryDepartment->name]
                : null,
        ];
    }
}
