<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalLessonFileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'journal_lesson_id' => $this->journal_lesson_id,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'uploaded_by' => $this->uploaded_by,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
