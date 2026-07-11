<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class PersonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'last_name' => $this->last_name,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'full_name' => collect([$this->last_name, $this->first_name, $this->middle_name])->filter()->implode(' '),
            'birth_date' => $this->birth_date?->toDateString(),
            'gender' => $this->gender,
            'citizenship' => $this->citizenship,
            'phone' => $this->phone,
            'email' => $this->email,
            'photo_path' => $this->photo_path,
            'photo_url' => $this->photo_path ? $request->getSchemeAndHttpHost().Storage::disk('public')->url($this->photo_path) : null,
            'snils' => $this->snils,
            'inn' => $this->inn,
            'status' => $this->status,
            'profiles_count' => [
                'students' => $this->whenCounted('students'),
                'teachers' => $this->whenCounted('teachers'),
                'applicant_applications' => $this->whenCounted('applicantApplications'),
                'graduates' => $this->whenCounted('graduates'),
                'users' => $this->whenCounted('users'),
                'digital_identities' => $this->whenCounted('digitalIdentities'),
            ],
            'students' => StudentResource::collection($this->whenLoaded('students')),
            'teachers' => TeacherResource::collection($this->whenLoaded('teachers')),
            'applicant_applications' => ApplicantApplicationResource::collection($this->whenLoaded('applicantApplications')),
            'graduates' => GraduateResource::collection($this->whenLoaded('graduates')),
            'users' => UserResource::collection($this->whenLoaded('users')),
            'digital_identities' => DigitalIdentityResource::collection($this->whenLoaded('digitalIdentities')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
