<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FisRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fis_package_id' => $this->fis_package_id,
            'applicant_application_id' => $this->applicant_application_id,
            'exam_id' => $this->exam_id,
            'student_id' => $this->student_id,
            'exam_result_id' => $this->exam_result_id,
            'graduate_id' => $this->graduate_id,
            'education_program_id' => $this->education_program_id,
            'specialty_id' => $this->specialty_id,
            'status' => $this->status,
            'payload' => $this->payload,
            'applicant_application' => new ApplicantApplicationResource($this->whenLoaded('applicantApplication')),
            'exam' => new ExamResource($this->whenLoaded('exam')),
            'student' => new StudentResource($this->whenLoaded('student')),
            'graduate' => new GraduateResource($this->whenLoaded('graduate')),
            'education_program' => new EducationProgramResource($this->whenLoaded('educationProgram')),
            'specialty' => new SpecialtyResource($this->whenLoaded('specialty')),
            'validation_errors' => FisValidationErrorResource::collection($this->whenLoaded('validationErrors')),
            'validation_errors_count' => $this->whenCounted('validationErrors'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
