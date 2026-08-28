<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class StudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'person_id' => $this->person_id,
            // Карты человека — под правом на их просмотр, а не всем, кто видит
            // карточку. Карта открывает дверь, и круг видящих её не должен
            // расширяться заодно с круглом видящих телефон.
            //
            // Карт бывает несколько: у четверых людей на 29.08.2026 их две-три,
            // и одно поле «номер карты» соврало бы на первой же такой карточке.
            // Состояние показывается у каждой: у кого карту забрали, не должен
            // видеть её действующей.
            'rfid_cards' => $this->when(
                (bool) $request->user()?->hasPermission('rfid.cards.view'),
                fn () => RfidCardResource::collection(
                    $this->person?->rfidCards->sortByDesc('issued_at')->values() ?? collect(),
                )->resolve(),
            ),

            'user_id' => $this->user_id,
            'group_id' => $this->group_id,
            'last_name' => $this->last_name,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'birth_date' => $this->birth_date?->toDateString(),
            'phone' => $this->phone,
            'email' => $this->email,
            'snils' => $this->when($request->user()?->hasPermission('students.update'), $this->snils),
            'address' => $this->address,
            'passport_series' => $this->when($request->user()?->hasPermission('students.update'), $this->passport_series),
            'passport_number' => $this->when($request->user()?->hasPermission('students.update'), $this->passport_number),
            'passport_issue_date' => $this->when($request->user()?->hasPermission('students.update'), $this->passport_issue_date?->toDateString()),
            'passport_issued_by' => $this->when($request->user()?->hasPermission('students.update'), $this->passport_issued_by),
            'passport_department_code' => $this->when($request->user()?->hasPermission('students.update'), $this->passport_department_code),
            'photo_path' => $this->photo_path,
            'photo_url' => $this->photo_path ? $request->getSchemeAndHttpHost().Storage::disk('public')->url($this->photo_path) : null,
            'status' => $this->status,
            // Курс — свойство группы: она считает его из года набора и не
            // устаревает. Собственный курс остаётся только у студента без
            // группы, которому взять его больше неоткуда.
            'course' => $this->group?->course ?? $this->course,
            'education_form' => $this->education_form,
            'funding_form' => $this->funding_form,
            'enrollment_date' => $this->enrollment_date?->toDateString(),
            'enrollment_order_number' => $this->enrollment_order_number,
            'enrollment_order_date' => $this->enrollment_order_date?->toDateString(),
            'personal_file_number' => $this->personal_file_number,
            'personal_file_letter' => $this->personal_file_letter,
            'specialty' => $this->group?->specialty ?? $this->group?->educationProgram?->specialty?->name,
            'archived_at' => $this->archived_at?->toISOString(),
            // Полнота карточки приходит из AdmissionDocumentReadinessService и кладётся
            // на модель контроллером; в списках она есть, в служебных ответах — нет.
            'card_completeness' => $this->whenNotNull($this->resource->card_completeness ?? null),
            'group' => new GroupResource($this->whenLoaded('group')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
