<?php

namespace App\Http\Resources;

use App\Models\RfidCardIssue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Строка журнала выдач — то, что комендант печатает и подшивает.
 *
 * Здесь намеренно нет ничего сверх нужного для журнала: фамилия, где человек
 * учится или работает, номер карты, даты и кто оформил. Паспорт, телефон и
 * адрес для выдачи карты не нужны и не показываются.
 */
class RfidCardIssueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $person = $this->person;
        $student = $person?->primaryStudent;
        $employee = $person?->primaryEmployee;

        return [
            'id' => $this->id,
            'card' => [
                'id' => $this->rfid_card_id,
                'uid' => $this->card?->uid,
                'label' => $this->card?->label,
            ],
            'person' => $person === null ? null : [
                'id' => $person->id,
                'full_name' => trim(implode(' ', array_filter([
                    $person->last_name,
                    $person->first_name,
                    $person->middle_name,
                ]))),
                'unit' => $student?->group?->name ?? $employee?->primaryDepartment?->name,
            ],
            'issued_at' => $this->issued_at?->toISOString(),
            'issued_by' => $this->issuedBy?->name,
            'returned_at' => $this->returned_at?->toISOString(),
            'accepted_by' => $this->acceptedBy?->name,
            'close_reason' => $this->close_reason,
            'close_reason_label' => RfidCardIssue::reasonLabel($this->close_reason),
            'is_open' => $this->returned_at === null,
            'note' => $this->note,
        ];
    }
}
