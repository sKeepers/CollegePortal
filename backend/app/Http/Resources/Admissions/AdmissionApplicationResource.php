<?php

namespace App\Http\Resources\Admissions;

use App\Http\Resources\EducationProgramResource;
use App\Services\Admissions\DocumentMaskingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdmissionApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $masking = app(DocumentMaskingService::class);

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'record_type' => $this->record_type,
            'foundation_version' => $this->foundation_version,
            'applicant_id' => $this->applicant_id,
            'admission_year' => $this->admission_year,
            'application_number' => $this->application_number,
            'education_program_id' => $this->education_program_id,
            'education_program' => new EducationProgramResource($this->whenLoaded('educationProgram')),
            'choices_count' => $this->whenCounted('choices'),
            'status' => [
                'code' => $this->statusCode(),
                'id' => $this->status_id,
                'name' => $this->statusItem?->name,
            ],
            'source' => $this->whenLoaded('source', fn () => [
                'id' => $this->source?->id,
                'code' => $this->source?->code,
                'name' => $this->source?->name,
            ]),
            'submitted_at' => $this->submitted_at?->toDateString(),
            'registered_at' => $this->registered_at?->toISOString(),
            'comment' => $this->comment,
            'applicant' => $this->whenLoaded('applicant', fn () => [
                'id' => $this->applicant?->id,
                'uuid' => $this->applicant?->uuid,
                'display_name' => trim(implode(' ', array_filter([
                    $this->applicant?->person?->last_name,
                    $this->applicant?->person?->first_name,
                    $this->applicant?->person?->middle_name,
                ]))),
                'person' => $this->applicant?->person ? [
                    'id' => $this->applicant->person->id,
                    'uuid' => $this->applicant->person->uuid,
                    'last_name' => $this->applicant->person->last_name,
                    'first_name' => $this->applicant->person->first_name,
                    'middle_name' => $this->applicant->person->middle_name,
                    'full_name' => trim(implode(' ', array_filter([
                        $this->applicant->person->last_name,
                        $this->applicant->person->first_name,
                        $this->applicant->person->middle_name,
                    ]))),
                    'birth_date' => $this->applicant->person->birth_date?->toDateString(),
                    'gender' => $this->applicant->person->gender,
                    'citizenship' => $this->applicant->person->citizenship,
                    'phone' => $this->applicant->person->phone,
                    'email' => $this->applicant->person->email,
                    'snils_masked' => $masking->snils($this->applicant->person->snils),
                    'has_snils' => filled($this->applicant->person->snils),
                    'status' => $this->applicant->person->status,
                ] : null,
            ]),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

}
