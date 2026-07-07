<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'users_count' => $this->assigned_users_count ?? $this->users_count ?? null,
            'permissions' => $this->whenLoaded(
                'permissions',
                fn () => $this->permissions->pluck('code')->values()
            ),
        ];
    }
}
