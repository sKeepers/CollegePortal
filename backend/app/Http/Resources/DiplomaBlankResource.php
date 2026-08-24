<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiplomaBlankResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->diploma_blank_batch_id,
            'kind' => $this->kind,
            'series' => $this->series,
            'number' => $this->number,
            'label' => $this->label(),
            'status' => $this->status,
            'graduate_id' => $this->graduate_id,
            'graduate_name' => $this->whenLoaded('graduate', fn (): ?string => $this->graduateName()),
            'diploma_id' => $this->diploma_id,
            'assigned_at' => $this->assigned_at?->toDateString(),
            'issued_at' => $this->issued_at?->toDateString(),
            'spoiled_at' => $this->spoiled_at?->toDateString(),
            'written_off_at' => $this->written_off_at?->toDateString(),
            'write_off_act' => $this->write_off_act,
            'reason' => $this->reason,
            'note' => $this->note,
            'batch' => new DiplomaBlankBatchResource($this->whenLoaded('batch')),
            'events' => DiplomaBlankEventResource::collection($this->whenLoaded('events')),
        ];
    }

    private function graduateName(): ?string
    {
        $student = $this->graduate?->student;

        if ($student === null) {
            return null;
        }

        return trim(implode(' ', array_filter([$student->last_name, $student->first_name, $student->middle_name])));
    }
}
