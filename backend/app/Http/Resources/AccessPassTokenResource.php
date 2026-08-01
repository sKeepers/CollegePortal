<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccessPassTokenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->resource;

        return [
            'token' => $data['token'],
            'issued_at' => $data['issued_at'],
            'expires_at' => $data['expires_at'],
            'ttl_seconds' => $data['ttl_seconds'],
            'qr_svg' => $data['qr_svg'],
            'person' => $data['person'],
        ];
    }
}
