<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UatTestRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $results = $this->whenLoaded('results');
        $counts = $this->relationLoaded('results') ? $this->results->countBy('status')->all() : [];
        return [
            'id' => $this->id,
            'title' => $this->title,
            'role_code' => $this->role_code,
            'tester_user_id' => $this->tester_user_id,
            'tester' => new UserResource($this->whenLoaded('tester')),
            'status' => $this->status,
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'summary' => $this->summary,
            'created_by' => $this->created_by,
            'creator' => new UserResource($this->whenLoaded('creator')),
            'progress' => [
                'total' => array_sum($counts),
                'passed' => $counts['passed'] ?? 0,
                'failed' => $counts['failed'] ?? 0,
                'blocked' => $counts['blocked'] ?? 0,
                'skipped' => $counts['skipped'] ?? 0,
                'not_started' => $counts['not_started'] ?? 0,
            ],
            'results' => UatTestResultResource::collection($results),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
