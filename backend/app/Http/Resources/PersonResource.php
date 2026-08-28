<?php

namespace App\Http\Resources;

use App\Http\Resources\Admissions\ApplicantResource;
use App\Services\Admissions\DocumentMaskingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class PersonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $masking = app(DocumentMaskingService::class);
        $canViewSensitive = (bool) ($request->user()?->hasPermission('admissions.document.download_sensitive')
            || $request->user()?->hasPermission('people.update'));

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'last_name' => $this->last_name,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'full_name' => collect([$this->last_name, $this->first_name, $this->middle_name])->filter()->implode(' '),
            'birth_date' => $this->birth_date?->toDateString(),
            'gender' => $this->gender,
            'citizenship' => $this->citizenship,
            'place_birth' => $this->place_birth,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'photo_path' => $this->photo_path,
            'photo_url' => $this->photo_path ? $request->getSchemeAndHttpHost().Storage::disk('public')->url($this->photo_path) : null,
            'snils' => $this->when($canViewSensitive, $this->snils),
            'snils_masked' => $masking->snils($this->snils),
            'has_snils' => filled($this->snils),
            'inn' => $this->inn,
            'status' => $this->status,
            'profiles_count' => [
                'students' => $this->whenCounted('students'),
                'teachers' => $this->whenCounted('teachers'),
                'employees' => $this->whenCounted('employees'),
                'applicants' => $this->whenCounted('applicants'),
                'applicant_applications' => $this->whenCounted('applicantApplications'),
                'graduates' => $this->whenCounted('graduates'),
                'users' => $this->whenCounted('users'),
                'digital_identities' => $this->whenCounted('digitalIdentities'),
            ],
            'students' => StudentResource::collection($this->whenLoaded('students')),
            'teachers' => TeacherResource::collection($this->whenLoaded('teachers')),
            'employees' => EmployeeResource::collection($this->whenLoaded('employees')),
            'applicants' => ApplicantResource::collection($this->whenLoaded('applicants')),
            'applicant_applications' => ApplicantApplicationResource::collection($this->whenLoaded('applicantApplications')),
            'graduates' => GraduateResource::collection($this->whenLoaded('graduates')),
            'users' => UserResource::collection($this->whenLoaded('users')),
            'digital_identities' => DigitalIdentityResource::collection($this->whenLoaded('digitalIdentities')),
            // Карта, которая сейчас на руках. Комендант заходит в «Люди» и
            // должен видеть её прямо в карточке, не переходя в раздел карт.
            //
            // Карт бывает **несколько** (слово владельца 28.08.2026), а поле
            // отдаёт одну — самую свежую. Поэтому рядом идёт их число: без
            // него карточка показывала бы одну карту и молчала об остальных,
            // а комендант, вернувший «ту самую», считал бы, что закрыл проход.
            'rfid_card' => $this->whenLoaded('currentRfidCard', fn () => $this->currentRfidCard === null ? null : [
                'id' => $this->currentRfidCard->id,
                'uid' => $this->currentRfidCard->uid,
                'label' => $this->currentRfidCard->label,
                'status' => $this->currentRfidCard->status,
                'status_label' => RfidCardResource::statusLabel($this->currentRfidCard->status),
                'issued_at' => $this->currentRfidCard->issued_at?->toISOString(),
            ]),
            'rfid_cards_on_hands' => $this->whenLoaded('rfidCardsOnHands', fn () => $this->rfidCardsOnHands->count()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
