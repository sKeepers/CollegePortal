<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiplomaBlankBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'series' => $this->series,
            'number_from' => $this->number_from,
            'number_to' => $this->number_to,
            'quantity' => $this->quantity,
            'blanks_count' => $this->whenCounted('blanks'),
            'received_at' => $this->received_at?->toDateString(),
            'supplier' => $this->supplier,
            'invoice_number' => $this->invoice_number,
            'received_by' => $this->whenLoaded('receivedBy', fn (): ?string => $this->receivedBy?->name),
            'note' => $this->note,
        ];
    }
}
