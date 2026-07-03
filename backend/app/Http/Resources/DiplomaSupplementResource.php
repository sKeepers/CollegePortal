<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiplomaSupplementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'diploma_id' => $this->diploma_id,
            'series' => $this->series,
            'number' => $this->number,
            'issue_date' => $this->issue_date?->toDateString(),
            'status' => $this->status,
            'subjects' => $this->subjects,
            'note' => $this->note,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
