<?php

namespace App\Http\Resources;

use App\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $definition = SettingService::definitions()[$this->group][$this->key] ?? [];

        return [
            'id' => $this->id,
            'group' => $this->group,
            'key' => $this->key,
            'value' => $this->value,
            'default_value' => $definition['value'] ?? null,
            'type' => $this->type,
            'is_public' => $this->is_public,
            'label' => $definition['label'] ?? $this->key,
            'description' => $this->description,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
