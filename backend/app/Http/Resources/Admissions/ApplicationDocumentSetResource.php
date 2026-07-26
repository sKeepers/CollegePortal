<?php

namespace App\Http\Resources\Admissions;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationDocumentSetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_id' => $this->application_id,
            'identity_document_id' => $this->identity_document_id,
            'education_document_id' => $this->education_document_id,
            'identity_document' => new IdentityDocumentResource($this->whenLoaded('identityDocument')),
            'education_document' => new EducationDocumentResource($this->whenLoaded('educationDocument')),
            'linked_by' => $this->linked_by,
            'linked_at' => $this->linked_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
