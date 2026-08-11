<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Models\Person;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    private function personSummary(): ?array
    {
        if ($this->relationLoaded('student') && $this->student) {
            return [
                'type' => 'student',
                'id' => $this->student->id,
                'name' => trim("{$this->student->last_name} {$this->student->first_name} {$this->student->middle_name}"),
            ];
        }

        if ($this->relationLoaded('teacher') && $this->teacher) {
            return [
                'type' => 'teacher',
                'id' => $this->teacher->id,
                'name' => trim("{$this->teacher->last_name} {$this->teacher->first_name} {$this->teacher->middle_name}"),
            ];
        }

        if (! $this->person_type || ! $this->person_id) {
            return null;
        }

        return match ($this->person_type) {
            'student' => $this->student ? [
                'type' => 'student',
                'id' => $this->student->id,
                'name' => trim("{$this->student->last_name} {$this->student->first_name} {$this->student->middle_name}"),
            ] : ['type' => 'student', 'id' => $this->person_id],
            'teacher' => $this->teacher ? [
                'type' => 'teacher',
                'id' => $this->teacher->id,
                'name' => trim("{$this->teacher->last_name} {$this->teacher->first_name} {$this->teacher->middle_name}"),
            ] : ['type' => 'teacher', 'id' => $this->person_id],
            // Провижининг связывает учетную запись с Person, а не с профилем, и
            // без этой ветки карточка показывала «не связана» рядом с ID записи.
            'person' => $this->personCardSummary(),
            'employee' => $this->employeeSummary(),
            default => ['type' => $this->person_type, 'id' => $this->person_id],
        };
    }

    private function personCardSummary(): array
    {
        $person = Person::query()->find($this->person_id);

        return [
            'type' => 'person',
            'id' => $this->person_id,
            'name' => $person ? trim("{$person->last_name} {$person->first_name} {$person->middle_name}") : null,
        ];
    }

    private function employeeSummary(): array
    {
        $employee = Employee::query()->with('person')->find($this->person_id);
        $person = $employee?->person;

        return [
            'type' => 'employee',
            'id' => $this->person_id,
            'name' => $person ? trim("{$person->last_name} {$person->first_name} {$person->middle_name}") : null,
        ];
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role_id' => $this->role_id,
            'name' => $this->name,
            'email' => $this->email,
            'username' => $this->username,
            'is_active' => $this->is_active,
            'status' => $this->is_active ? 'active' : 'blocked',
            // Признак «ходит с выданным паролем». Фронтенд по нему предлагает завести
            // свой сразу после входа; секрета в самом признаке нет.
            'must_change_password' => (bool) $this->must_change_password,
            'role' => new RoleResource($this->whenLoaded('role')),
            'roles' => RoleResource::collection($this->whenLoaded('roles')),
            'permissions' => method_exists($this->resource, 'permissionCodes') ? $this->permissionCodes() : [],
            'last_login_at' => $this->last_login_at?->toISOString(),
            'person_type' => $this->person_type,
            'person_id' => $this->person_id,
            'person' => $this->personSummary(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
