<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiplomaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'graduate_id' => $this->graduate_id,
            'series' => $this->series,
            'number' => $this->number,
            'registration_number' => $this->registration_number,
            'issue_date' => $this->issue_date?->toDateString(),
            'qualification' => $this->qualification,
            'gia_decision' => $this->gia_decision,
            'status' => $this->status,
            'note' => $this->note,
            'supplement' => new DiplomaSupplementResource($this->whenLoaded('supplement')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
