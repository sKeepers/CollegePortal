<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    private function personSummary(): ?array
    {
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
            default => ['type' => $this->person_type, 'id' => $this->person_id],
        };
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role_id' => $this->role_id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'must_change_password' => (bool) $this->must_change_password,
            'status' => $this->is_active ? 'active' : 'blocked',
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
