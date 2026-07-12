<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UatTestResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'test_run_id' => $this->test_run_id,
            'scenario_code' => $this->scenario_code,
            'status' => $this->status,
            'comment' => $this->comment,
            'actual_result' => $this->actual_result,
            'has_screenshot' => (bool) $this->screenshot_path,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
