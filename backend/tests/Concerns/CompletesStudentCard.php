<?php

namespace Tests\Concerns;

use App\Models\Admissions\EducationDocument;
use App\Models\Admissions\IdentityDocument;
use App\Models\Person;
use App\Models\Student;
use Illuminate\Support\Str;

/**
 * Диплом, ФРДО и ФИС ГИА блокируются при неполной карточке студента, поэтому
 * тесты этих операций обязаны сначала довести карточку до полной.
 */
trait CompletesStudentCard
{
    protected function completeStudentCard(Student $student, string $snils = '112-233-445 95'): Student
    {
        $person = $student->person ?: Person::query()->create([
            'last_name' => $student->last_name,
            'first_name' => $student->first_name,
            'middle_name' => $student->middle_name,
            'birth_date' => $student->birth_date?->toDateString(),
            'status' => 'active',
        ]);

        $person->forceFill([
            'snils' => $snils,
            'snils_hash' => hash('sha256', preg_replace('/\D+/', '', $snils)),
        ])->save();

        $student->forceFill(['person_id' => $person->id, 'snils' => $snils])->save();

        IdentityDocument::query()->create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'series' => '0712',
            'number' => '345678',
            'issue_date' => '2025-03-01',
            'is_primary' => true,
            'verification_status' => IdentityDocument::STATUS_VERIFIED,
        ]);

        EducationDocument::query()->create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'series' => 'АБ',
            'number' => '123456',
            'issue_date' => '2023-06-20',
            'document_organization' => 'Тестовая школа',
            'graduation_year' => 2023,
            'is_primary' => true,
            'verification_status' => EducationDocument::STATUS_VERIFIED,
        ]);

        return $student->refresh();
    }
}
