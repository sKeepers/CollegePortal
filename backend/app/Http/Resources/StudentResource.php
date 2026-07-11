<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class StudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'person_id' => $this->person_id,
            'user_id' => $this->user_id,
            'group_id' => $this->group_id,
            'last_name' => $this->last_name,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'birth_date' => $this->birth_date?->toDateString(),
            'phone' => $this->phone,
            'email' => $this->email,
            'photo_path' => $this->photo_path,
            'photo_url' => $this->photo_path ? $request->getSchemeAndHttpHost().Storage::disk('public')->url($this->photo_path) : null,
            'status' => $this->status,
            'enrollment_date' => $this->enrollment_date?->toDateString(),
            'group' => new GroupResource($this->whenLoaded('group')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
