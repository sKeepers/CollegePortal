<?php

namespace App\Services\Documents;

use App\Models\Student;
use App\Models\StudentOrder;
use App\Services\SettingService;
use Illuminate\Support\Carbon;

class DocumentVariableResolver
{
    public function resolveStudentEnrollmentCertificate(Student $student, array $overrides = []): array
    {
        $student->loadMissing(['person', 'group.educationProgram.specialty']);
        $enrollmentOrder = StudentOrder::query()->where('student_id', $student->id)->where('order_type', 'enrollment')->latest('order_date')->first();
        $courseOrder = StudentOrder::query()->where('student_id', $student->id)->whereIn('order_type', ['course_promotion', 'transfer'])->latest('order_date')->first();

        $fullName = trim(implode(' ', array_filter([
            $student->last_name ?: $student->person?->last_name,
            $student->first_name ?: $student->person?->first_name,
            $student->middle_name ?: $student->person?->middle_name,
        ])));

        $variables = [
            'organization.full_name' => SettingService::value('organization', 'full_name', SettingService::value('general', 'college_full_name', '')),
            'organization.short_name' => SettingService::value('organization', 'short_name', SettingService::value('general', 'college_short_name', '')),
            'organization.department_name' => SettingService::value('organization', 'department_name', ''),
            'organization.address' => SettingService::value('organization', 'address', SettingService::value('general', 'college_address', '')),
            'organization.phone' => SettingService::value('organization', 'phone', SettingService::value('general', 'college_phone', '')),
            'organization.email' => SettingService::value('organization', 'email', SettingService::value('general', 'college_email', '')),
            'document.issue_date' => now()->format('d.m.Y'),
            'student.full_name' => $fullName,
            'student.birth_date' => $this->date($student->birth_date ?: $student->person?->birth_date),
            'student.course' => $student->course,
            'student.group' => $student->group?->name,
            'student.education_form' => $student->education_form,
            'student.funding_type' => $student->funding_form,
            'student.specialty_code' => $student->group?->educationProgram?->specialty?->code,
            'student.specialty_name' => $student->group?->educationProgram?->specialty?->name ?? $student->group?->specialty,
            'student.profile_name' => null,
            'student.enrollment_order.number' => $enrollmentOrder?->order_number,
            'student.enrollment_order.date' => $this->date($enrollmentOrder?->order_date),
            'student.current_course_order.number' => $courseOrder?->order_number,
            'student.current_course_order.date' => $this->date($courseOrder?->order_date),
            'student.study_started_at' => $this->date($student->enrollment_date),
            'student.expected_graduation_at' => null,
            'signer.position' => SettingService::value('organization', 'head_position', 'Руководитель'),
            'signer.full_name' => SettingService::value('organization', 'head_full_name', ''),
        ];

        $variables = array_merge($variables, $overrides);

        $required = [
            'organization.full_name' => 'Полное наименование организации',
            'student.full_name' => 'ФИО студента',
            'student.course' => 'Курс',
            'student.group' => 'Группа',
            'student.education_form' => 'Форма обучения',
            'student.funding_type' => 'Форма финансирования',
            'signer.full_name' => 'ФИО подписанта',
        ];

        $missing = [];
        foreach ($required as $key => $label) {
            if ($variables[$key] === null || $variables[$key] === '') {
                $missing[] = ['key' => $key, 'label' => $label];
            }
        }

        return ['variables' => $variables, 'missing' => $missing, 'warnings' => []];
    }

    private function date(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->format('d.m.Y') : null;
    }
}
