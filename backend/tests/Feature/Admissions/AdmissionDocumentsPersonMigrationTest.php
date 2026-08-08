<?php

namespace Tests\Feature\Admissions;

use App\Models\Admissions\Applicant;
use App\Models\Admissions\EducationDocument;
use App\Models\Admissions\IdentityDocument;
use App\Models\Person;
use App\Models\ReferenceCatalog;
use App\Models\ReferenceItem;
use Database\Seeders\AdmissionReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Самое дорогое место миграции документов на уровень человека — обратимость.
 * Проверяется полный цикл вперёд → назад → вперёд: данные приёмной комиссии
 * обязаны пережить откат, а `person_id` — восстановиться из абитуриента.
 */
class AdmissionDocumentsPersonMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_08_09_000001_lift_admission_documents_to_person.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AdmissionReferenceSeeder::class);
    }

    public function test_rollback_keeps_admission_documents_and_forward_restores_person(): void
    {
        $applicant = $this->createApplicant('Абитуриент');
        $identity = $this->createIdentity($applicant->person_id, $applicant->id);
        $education = $this->createEducation($applicant->person_id, $applicant->id);

        $migration = $this->migration();
        $migration->down();

        $this->assertDatabaseHas('admission_identity_documents', [
            'id' => $identity->id,
            'applicant_id' => $applicant->id,
        ]);
        $this->assertDatabaseHas('admission_education_documents', [
            'id' => $education->id,
            'applicant_id' => $applicant->id,
        ]);
        $this->assertFalse(
            Schema::hasColumn('admission_education_documents', 'person_id'),
            'После отката документ об образовании снова не знает человека.',
        );

        $migration->up();

        $this->assertDatabaseHas('admission_identity_documents', [
            'id' => $identity->id,
            'person_id' => $applicant->person_id,
        ]);
        $this->assertDatabaseHas('admission_education_documents', [
            'id' => $education->id,
            'person_id' => $applicant->person_id,
            'applicant_id' => $applicant->id,
        ]);
    }

    public function test_rollback_drops_only_documents_without_applicant(): void
    {
        $applicant = $this->createApplicant('Абитуриент');
        $admissionDocument = $this->createIdentity($applicant->person_id, $applicant->id);

        $studentPerson = Person::query()->create([
            'last_name' => 'Переведённый',
            'first_name' => 'Студент',
            'birth_date' => '2007-05-06',
            'status' => 'active',
        ]);
        $studentDocument = $this->createIdentity($studentPerson->id, null);

        $this->migration()->down();

        $this->assertDatabaseHas('admission_identity_documents', ['id' => $admissionDocument->id]);
        $this->assertDatabaseMissing('admission_identity_documents', ['id' => $studentDocument->id]);
    }

    private function migration(): object
    {
        return require base_path(self::MIGRATION);
    }

    private function createApplicant(string $firstName): Applicant
    {
        $person = Person::query()->create([
            'last_name' => 'Миграция',
            'first_name' => $firstName,
            'birth_date' => '2008-03-04',
            'status' => 'active',
        ]);

        return Applicant::query()->create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'status_id' => $this->referenceItemId('applicant_statuses', 'active'),
            'source_id' => $this->referenceItemId('admission_sources', 'manual'),
        ]);
    }

    private function createIdentity(int $personId, ?int $applicantId): IdentityDocument
    {
        return IdentityDocument::query()->create([
            'uuid' => (string) Str::uuid(),
            'applicant_id' => $applicantId,
            'person_id' => $personId,
            'series' => '1234',
            'number' => '567890',
            'issue_date' => '2026-07-01',
            'is_primary' => true,
            'verification_status' => IdentityDocument::STATUS_RECEIVED,
        ]);
    }

    private function createEducation(int $personId, ?int $applicantId): EducationDocument
    {
        return EducationDocument::query()->create([
            'uuid' => (string) Str::uuid(),
            'applicant_id' => $applicantId,
            'person_id' => $personId,
            'series' => 'АБ',
            'number' => '123456',
            'issue_date' => '2026-06-20',
            'document_organization' => 'Демонстрационная школа',
            'graduation_year' => 2026,
            'is_primary' => true,
            'verification_status' => EducationDocument::STATUS_RECEIVED,
        ]);
    }

    private function referenceItemId(string $catalogCode, string $itemCode): int
    {
        $catalog = ReferenceCatalog::query()->where('code', $catalogCode)->firstOrFail();

        return ReferenceItem::query()
            ->where('catalog_id', $catalog->id)
            ->where('code', $itemCode)
            ->firstOrFail()
            ->id;
    }
}
