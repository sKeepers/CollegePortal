<?php

namespace App\Http\Resources;

use App\Models\DiplomaBlankEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiplomaBlankEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'action_label' => DiplomaBlankEvent::ACTION_LABELS[$this->action] ?? $this->action,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'graduate_id' => $this->graduate_id,
            'user' => $this->whenLoaded('user', fn (): ?string => $this->user?->name),
            'act_number' => $this->act_number,
            'reason' => $this->reason,
            'happened_at' => $this->happened_at?->toISOString(),
        ];
    }
}
