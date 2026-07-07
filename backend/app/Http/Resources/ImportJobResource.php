<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ImportJobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => new UserResource($this->whenLoaded('user')),
            'data_type' => $this->data_type,
            'mode' => $this->mode,
            'status' => $this->status,
            'original_filename' => $this->original_filename,
            'headers' => $this->headers ?? [],
            'mapping' => $this->mapping ?? [],
            'preview_rows' => $this->preview_rows ?? [],
            'validation_errors' => $this->validation_errors ?? [],
            'result' => $this->result ?? null,
            'total_rows' => $this->total_rows,
            'created_count' => $this->created_count,
            'updated_count' => $this->updated_count,
            'skipped_count' => $this->skipped_count,
            'error_count' => $this->error_count,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
