<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role_id' => $this->role_id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'role' => new RoleResource($this->whenLoaded('role')),
            'last_login_at' => $this->last_login_at?->toISOString(),
        ];
    }
}
