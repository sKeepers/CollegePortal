<?php

namespace Tests\Feature\Admissions;

use App\Models\Admissions\Applicant;
use App\Models\Admissions\IdentityDocument;
use App\Models\AuditLog;
use App\Models\Person;
use App\Models\ReferenceCatalog;
use App\Models\ReferenceItem;
use Database\Seeders\AdmissionReferenceSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonApplicantManagementApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(AdmissionReferenceSeeder::class);
    }

    public function test_admission_user_can_create_and_update_person(): void
    {
        $user = $this->createApiUser(roleCode: 'admission');

        $personId = $this->withApiAuth($user)
            ->postJson('/api/people', [
                'last_name' => 'Новый',
                'first_name' => 'Абитуриент',
                'birth_date' => '2008-02-03',
                'email' => 'new.applicant@example.test',
                'phone' => '+7 (900) 111-22-33',
                'snils' => '112-233-445 95',
            ])
            ->assertCreated()
            ->assertJsonPath('data.last_name', 'Новый')
            ->assertJsonPath('data.phone', '79001112233')
            ->assertJsonPath('data.snils_masked', '***-***-445 95')
            ->json('data.id');

        $this->withApiAuth($user)
            ->patchJson("/api/people/{$personId}", [
                'email' => 'updated.applicant@example.test',
                'address' => 'Демонстрационный адрес',
            ])
            ->assertOk()
            ->assertJsonPath('data.email', 'updated.applicant@example.test')
            ->assertJsonPath('data.address', 'Демонстрационный адрес');

        $this->assertTrue(AuditLog::query()->where('action', 'person_created')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'person_updated')->exists());
    }

    public function test_admission_user_can_create_applicant_with_new_person(): void
    {
        $user = $this->createApiUser(roleCode: 'admission');

        $this->withApiAuth($user)
            ->postJson('/api/admissions/applicants', [
                'person' => [
                    'last_name' => 'Профиль',
                    'first_name' => 'Новый',
                    'birth_date' => '2007-04-05',
                    'email' => 'foundation-new@example.test',
                ],
                'source_code' => 'manual',
                'status_code' => 'active',
                'notes' => 'Создано из API BACK-006.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.person.last_name', 'Профиль')
            ->assertJsonPath('data.source.code', 'manual')
            ->assertJsonPath('data.status.code', 'active');

        $this->assertSame(1, Person::query()->count());
        $this->assertSame(1, Applicant::query()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'applicant_created')->exists());
    }

    public function test_admission_user_can_create_applicant_with_existing_person_and_update_archive_it(): void
    {
        $user = $this->createApiUser(roleCode: 'admission');
        $person = Person::query()->create([
            'uuid' => (string) Str::uuid(),
            'last_name' => 'Связанный',
            'first_name' => 'Абитуриент',
            'status' => 'active',
        ]);

        $applicantId = $this->withApiAuth($user)
            ->postJson('/api/admissions/applicants', [
                'person_id' => $person->id,
                'source_code' => 'manual',
                'status_code' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.person_id', $person->id)
            ->json('data.id');

        $this->withApiAuth($user)
            ->patchJson("/api/admissions/applicants/{$applicantId}", [
                'notes' => 'Ответственный оператор обновил профиль.',
            ])
            ->assertOk()
            ->assertJsonPath('data.notes', 'Ответственный оператор обновил профиль.');

        $this->withApiAuth($user)
            ->postJson("/api/admissions/applicants/{$applicantId}/archive")
            ->assertOk()
            ->assertJsonPath('data.id', $applicantId);

        $this->assertNotNull(Applicant::query()->find($applicantId)?->archived_at);
        $this->assertTrue(AuditLog::query()->where('action', 'applicant_updated')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'applicant_archived')->exists());
    }

    public function test_duplicate_check_finds_matches_by_snils_email_phone_passport_and_full_name_birth_date(): void
    {
        $user = $this->createApiUser(roleCode: 'admission');
        $person = Person::query()->create([
            'uuid' => (string) Str::uuid(),
            'last_name' => 'Дубль',
            'first_name' => 'Проверочный',
            'middle_name' => 'Тестовый',
            'birth_date' => '2008-01-02',
            'snils' => '11223344595',
            'email' => 'duplicate@example.test',
            'phone' => '79000000000',
            'status' => 'active',
        ]);
        $applicant = $this->createApplicant($person);
        IdentityDocument::query()->create([
            'uuid' => (string) Str::uuid(),
            'version_number' => 1,
            'applicant_id' => $applicant->id,
            'person_id' => $person->id,
            'series' => '1234',
            'number' => '567890',
            'number_hash' => hash('sha256', mb_strtolower('1234|567890')),
            'verification_status' => IdentityDocument::STATUS_RECEIVED,
        ]);

        $response = $this->withApiAuth($user)
            ->postJson('/api/people/duplicates/check', [
                'last_name' => 'Дубль',
                'first_name' => 'Проверочный',
                'middle_name' => 'Тестовый',
                'birth_date' => '2008-01-02',
                'snils' => '112-233-445 95',
                'email' => 'duplicate@example.test',
                'phone' => '+7 900 000-00-00',
                'identity_document' => [
                    'series' => '1234',
                    'number' => '567890',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.has_matches', true)
            ->assertJsonPath('data.matches_count', 1);

        $this->assertEqualsCanonicalizing([
            'snils',
            'email',
            'phone',
            'full_name_birth_date',
            'identity_document',
        ], $response->json('data.matches.0.matched_by'));
        $this->assertTrue(AuditLog::query()->where('action', 'person_duplicate_check')->exists());
    }

    public function test_ambiguous_duplicate_blocks_applicant_auto_linking(): void
    {
        $user = $this->createApiUser(roleCode: 'admission');
        Person::query()->create(['uuid' => (string) Str::uuid(), 'last_name' => 'Одинаковый', 'first_name' => 'Телефон', 'phone' => '79005550101', 'status' => 'active']);
        Person::query()->create(['uuid' => (string) Str::uuid(), 'last_name' => 'Другой', 'first_name' => 'Телефон', 'phone' => '79005550101', 'status' => 'active']);

        $this->withApiAuth($user)
            ->postJson('/api/admissions/applicants', [
                'person' => [
                    'last_name' => 'Новый',
                    'first_name' => 'Кандидат',
                    'phone' => '+7 900 555-01-01',
                ],
                'source_code' => 'manual',
                'status_code' => 'active',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['person_id', 'duplicate_person_ids']);

        $this->assertSame(0, Applicant::query()->count());
    }

    public function test_rbac_for_person_and_applicant_management(): void
    {
        $student = $this->createApiUser(roleCode: 'student');

        $this->withApiAuth($student)
            ->postJson('/api/people', ['last_name' => 'Нет', 'first_name' => 'Прав'])
            ->assertForbidden();

        $this->withApiAuth($student)
            ->postJson('/api/admissions/applicants', [
                'person' => ['last_name' => 'Нет', 'first_name' => 'Прав'],
            ])
            ->assertForbidden();
    }

    public function test_merge_endpoint_is_explicitly_not_supported(): void
    {
        $user = $this->createApiUser(roleCode: 'admission');

        $this->withApiAuth($user)
            ->postJson('/api/people/merge', ['source_id' => 1, 'target_id' => 2])
            ->assertStatus(501)
            ->assertJsonPath('code', 'merge_not_supported');
    }

    private function createApplicant(Person $person): Applicant
    {
        return Applicant::query()->create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'source_id' => $this->referenceItemId('admission_sources', 'manual'),
            'status_id' => $this->referenceItemId('applicant_statuses', 'active'),
            'first_contact_at' => now(),
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
