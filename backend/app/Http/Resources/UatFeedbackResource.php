<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UatFeedbackResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => new UserResource($this->whenLoaded('user')),
            'role_code' => $this->role_code,
            'category' => $this->category,
            'severity' => $this->severity,
            'title' => $this->title,
            'description' => $this->description,
            'expected_result' => $this->expected_result,
            'actual_result' => $this->actual_result,
            'page_url' => $this->page_url,
            'app_version' => $this->app_version,
            'build_hash' => $this->build_hash,
            'environment' => $this->environment,
            'has_screenshot' => (bool) $this->screenshot_path,
            'status' => $this->status,
            'assigned_to' => $this->assigned_to,
            'assignee' => new UserResource($this->whenLoaded('assignee')),
            'resolution' => $this->resolution,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
