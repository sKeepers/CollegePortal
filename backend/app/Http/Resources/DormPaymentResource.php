<?php

namespace App\Http\Resources;

use App\Models\DormPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DormPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'paid_through' => $this->paid_through?->toDateString(),
            'amount' => $this->amount === null ? null : (float) $this->amount,
            'paid_at' => $this->paid_at?->toDateString(),
            'origin' => $this->origin,
            'origin_label' => $this->origin === DormPayment::ORIGIN_1C ? 'Из 1С' : 'Отметил комендант',
            'external_id' => $this->external_id,
            // Замещённая отметка остаётся видимой: работа коменданта никуда не
            // делась, её просто перекрыла строка из 1С.
            'is_superseded' => $this->superseded_by_id !== null,
            'superseded_by_id' => $this->superseded_by_id,
            'note' => $this->note,
            'created_by' => $this->createdBy?->name,
        ];
    }
}
