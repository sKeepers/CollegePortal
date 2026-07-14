<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GeneratedDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'type' => new DocumentTypeResource($this->whenLoaded('type')),
            'template' => new DocumentTemplateResource($this->whenLoaded('template')),
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'registration_number' => $this->registration_number,
            'issue_date' => $this->issue_date?->format('d.m.Y'),
            'status' => $this->status,
            'has_docx' => $this->output_docx_path !== null,
            'has_pdf' => $this->output_pdf_path !== null,
            'verification_public_id' => $this->verification_public_id,
            'issued_at' => $this->issued_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'cancellation_reason' => $this->cancellation_reason,
            'reprint_count' => $this->reprint_count,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
