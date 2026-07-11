<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicantApplicationDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $metadata = $this->documentType?->metadata ?? [];
        $status = $this->status ?: ($this->is_received ? 'received' : 'missing');

        return [
            'id' => $this->id,
            'document_type_id' => $this->document_type_id,
            'type' => $this->documentType?->code ?: $this->type,
            'title' => $this->documentType?->name ?: $this->title,
            'required' => (bool) ($metadata['required'] ?? true),
            'allowed_extensions' => $metadata['allowed_extensions'] ?? ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
            'max_size_mb' => $metadata['max_size_mb'] ?? 10,
            'status' => $status,
            'is_received' => in_array($status, ['received', 'under_review', 'verified'], true),
            'received_at' => $this->received_at?->toDateString(),
            'received_by' => $this->receiver?->name,
            'verified_at' => $this->verified_at?->toISOString(),
            'verified_by' => $this->verifier?->name,
            'rejection_reason' => $this->rejection_reason,
            'number' => $this->number,
            'comment' => $this->comment,
            'source' => $this->source,
            'files_count' => $this->files_count ?? ($this->relationLoaded('files') ? $this->files->count() : 0),
            'files' => ApplicantDocumentFileResource::collection($this->whenLoaded('files')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
