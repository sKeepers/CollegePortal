<?php

namespace Tests\Feature\Admissions;

use App\Models\Admissions\Applicant;
use App\Models\AuditLog;
use App\Models\Person;
use App\Models\ReferenceCatalog;
use App\Models\ReferenceItem;
use App\Models\Role;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature-тесты read-only API foundation-профилей абитуриентов.
 */
class ApplicantApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_permission_can_read_applicants(): void
    {
        $this->seed(RoleSeeder::class);
        $applicant = $this->createApplicant();
        $user = $this->createApiUser(roleCode: 'admission');

        $this->withApiAuth($user)
            ->getJson('/api/admissions/applicants')
            ->assertOk()
            ->assertJsonPath('data.0.id', $applicant->id)
            ->assertJsonPath('data.0.person.last_name', 'Поступающий')
            ->assertJsonPath('data.0.status.code', 'active')
            ->assertJsonPath('data.0.source.code', 'manual');
    }

    public function test_show_endpoint_returns_single_applicant_card(): void
    {
        $this->seed(RoleSeeder::class);
        $applicant = $this->createApplicant();
        $user = $this->createApiUser(roleCode: 'admission');

        $this->withApiAuth($user)
            ->getJson("/api/admissions/applicants/{$applicant->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $applicant->id)
            ->assertJsonPath('data.uuid', $applicant->uuid)
            ->assertJsonPath('data.person.email', 'applicant-api@example.test');

        $this->assertTrue(AuditLog::query()->where('action', 'applicant_show')->exists());
    }

    public function test_filters_by_status_source_and_search(): void
    {
        $this->seed(RoleSeeder::class);
        $this->createApplicant();
        $this->createApplicant([
            'last_name' => 'Архивный',
            'email' => 'archived-applicant@example.test',
        ], [
            'archived_at' => now(),
        ]);
        $user = $this->createApiUser(roleCode: 'admission');

        $this->withApiAuth($user)
            ->getJson('/api/admissions/applicants?status=active&source=manual&q=Поступающий')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.person.last_name', 'Поступающий');

        $this->withApiAuth($user)
            ->getJson('/api/admissions/applicants?with_archived=true')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_user_without_permission_cannot_read_applicants(): void
    {
        $this->seed(RoleSeeder::class);
        $student = $this->createApiUser(roleCode: 'student');

        $this->withApiAuth($student)
            ->getJson('/api/admissions/applicants')
            ->assertForbidden();
    }

    public function test_applicants_api_is_get_only(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->createApiUser(roleCode: 'admin');

        $this->withApiAuth($admin)
            ->postJson('/api/admissions/applicants', [])
            ->assertStatus(405);
    }

    public function test_permissions_are_registered_for_roles(): void
    {
        $this->seed(RoleSeeder::class);

        $this->assertDatabaseHas('permissions', [
            'code' => 'admissions.applicant.view',
            'module' => 'Admissions',
            'active' => true,
        ]);
        $this->assertDatabaseHas('permissions', [
            'code' => 'admissions.applicant.manage',
            'module' => 'Admissions',
            'active' => true,
        ]);

        $admissionRole = Role::query()->where('code', 'admission')->firstOrFail();
        $codes = $admissionRole->permissions()->pluck('code')->all();

        $this->assertContains('admissions.applicant.view', $codes);
        $this->assertContains('admissions.applicant.manage', $codes);
    }

    /**
     * @param array<string, mixed> $personOverrides
     * @param array<string, mixed> $applicantOverrides
     */
    private function createApplicant(array $personOverrides = [], array $applicantOverrides = []): Applicant
    {
        $person = Person::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'last_name' => 'Поступающий',
            'first_name' => 'Тест',
            'middle_name' => 'Безличный',
            'birth_date' => '2007-09-01',
            'email' => 'applicant-api@example.test',
            'status' => 'active',
        ], $personOverrides));

        return Applicant::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'source_id' => $this->referenceItemId('admission_sources', 'manual'),
            'status_id' => $this->referenceItemId('applicant_statuses', 'active'),
            'first_contact_at' => now(),
        ], $applicantOverrides));
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
