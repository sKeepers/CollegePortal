<?php

namespace App\Http\Resources;

use App\Models\DormSocialRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DormSocialRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $student = $this->student;

        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'student' => $student === null ? null : [
                'id' => $student->id,
                'full_name' => trim(implode(' ', array_filter([$student->last_name, $student->first_name, $student->middle_name]))),
                'group' => $student->group?->name,
            ],
            'category' => $this->category,
            'category_label' => DormSocialRecord::categoryLabel($this->category),
            'details' => $this->details,
            'opened_on' => $this->opened_on?->toDateString(),
            'closed_on' => $this->closed_on?->toDateString(),
            'is_open' => $this->closed_on === null,
            'created_by' => $this->createdBy?->name,
        ];
    }
}
