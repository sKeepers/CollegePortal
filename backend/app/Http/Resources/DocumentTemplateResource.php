<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_type_id' => $this->document_type_id,
            'type' => new DocumentTypeResource($this->whenLoaded('type')),
            'name' => $this->name,
            'version' => $this->version,
            'status' => $this->status,
            'source_format' => $this->source_format,
            'template_path' => $this->template_path,
            'output_formats' => $this->output_formats,
            'variables_schema' => $this->variables_schema,
            'published_at' => $this->published_at?->toISOString(),
            'notes' => $this->notes,
        ];
    }
}
