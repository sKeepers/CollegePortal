<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Строка реестра справок.
 *
 * Отдаётся снимок, записанный при выдаче, а не сегодняшняя карточка студента:
 * справка — документ, и она не меняется, когда студента переводят на курс выше.
 * Ссылки на студента и группу идут рядом справочно — для поиска на экране.
 */
class StudentCertificateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => (int) $this->number,
            // Откуда строка: выдана порталом или перенесена с бумаги. У второй
            // половины полей нет, и экран обязан это показывать, а не делать
            // вид, что документ можно воспроизвести.
            'source' => $this->source,
            'issued_on' => $this->issued_on?->toDateString(),
            'received_on' => $this->received_on?->toDateString(),
            'full_name' => $this->full_name,
            'birth_date' => $this->birth_date?->toDateString(),
            'course' => (int) $this->course,
            'specialty' => $this->specialty,
            'study_form' => $this->study_form,
            'enrollment_order_number' => $this->enrollment_order_number,
            'enrollment_order_date' => $this->enrollment_order_date?->toDateString(),
            'transfer_order_number' => $this->transfer_order_number,
            'transfer_order_date' => $this->transfer_order_date?->toDateString(),
            'study_start' => $this->study_start?->toDateString(),
            'study_end' => $this->study_end?->toDateString(),
            'note' => $this->note,
            'student_id' => $this->student_id,
            'group' => $this->whenLoaded('student', fn () => $this->student?->group?->name),
            'issued_by' => $this->whenLoaded('issuedBy', fn () => $this->issuedBy?->email),
        ];
    }
}
