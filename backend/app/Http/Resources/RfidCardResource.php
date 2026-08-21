<?php

namespace App\Http\Resources;

use App\Models\RfidCard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RfidCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uid' => $this->uid,
            'label' => $this->label,
            'status' => $this->status,
            'status_label' => self::statusLabel($this->status),
            'person_id' => $this->person_id,
            'person' => $this->whenLoaded('person', fn () => [
                'id' => $this->person?->id,
                'full_name' => trim(implode(' ', array_filter([
                    $this->person?->last_name,
                    $this->person?->first_name,
                    $this->person?->middle_name,
                ]))),
            ]),
            // Удаляется только карта без истории: с историей вместе с ней
            // каскадом ушёл бы журнал выдач. null — счётчик не запрашивали.
            'can_delete' => $this->issues_count === null ? null : $this->issues_count === 0,
            'issued_at' => $this->issued_at?->toDateString(),
            'returned_at' => $this->returned_at?->toDateString(),
            'note' => $this->note,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    public static function statusLabel(?string $status): string
    {
        return match ($status) {
            RfidCard::STATUS_STOCK => 'На складе',
            RfidCard::STATUS_ISSUED => 'На руках',
            RfidCard::STATUS_LOST => 'Утеряна',
            RfidCard::STATUS_BLOCKED => 'Заблокирована',
            RfidCard::STATUS_WRITTEN_OFF => 'Списана',
            default => (string) $status,
        };
    }
}
