<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ReferenceCatalog;
use App\Models\ReferenceItem;
use App\Support\Admissions\AdmissionReferenceCatalogs;
use Database\Seeders\AdmissionReferenceSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature-тесты read-only API справочников приемной комиссии.
 */
class AdmissionReferenceApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Проверяет, что миграция и seeder создают справочники и permissions BACK-001.
     */
    public function test_migration_and_seeder_create_admission_reference_catalogs(): void
    {
        $this->seed(AdmissionReferenceSeeder::class);

        $this->assertDatabaseHas('permissions', [
            'code' => 'admissions.reference.view',
            'module' => 'Admissions',
            'active' => true,
        ]);
        $this->assertDatabaseHas('permissions', [
            'code' => 'admissions.reference.manage',
            'module' => 'Admissions',
            'active' => true,
        ]);

        foreach (AdmissionReferenceCatalogs::codes() as $code) {
            $this->assertDatabaseHas('reference_catalogs', [
                'code' => $code,
                'is_system' => true,
            ]);
        }

        $this->assertDatabaseHas('reference_items', [
            'code' => 'ready_for_competition',
            'name' => 'Готово к конкурсу',
            'is_active' => true,
        ]);
    }

    /**
     * Проверяет доступ пользователя приемной комиссии к списку справочников.
     */
    public function test_user_with_permission_can_read_admission_references(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(AdmissionReferenceSeeder::class);

        $user = $this->createApiUser(roleCode: 'admission');

        $response = $this->withApiAuth($user)
            ->getJson('/api/admissions/reference')
            ->assertOk();

        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertContains('applicant_document_types', $codes);
    }

    /**
     * Проверяет получение одного справочника вместе с элементами.
     */
    public function test_single_catalog_endpoint_returns_items(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(AdmissionReferenceSeeder::class);

        $user = $this->createApiUser(roleCode: 'admission');

        $this->withApiAuth($user)
            ->getJson('/api/admissions/reference/applicant_document_types')
            ->assertOk()
            ->assertJsonPath('data.code', 'applicant_document_types')
            ->assertJsonFragment(['code' => 'passport', 'name' => 'Паспорт']);
    }

    /**
     * Проверяет отказ при запросе неизвестного admissions-справочника.
     */
    public function test_catalog_filter_rejects_unknown_admission_reference(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->createApiUser(roleCode: 'admission');

        $this->withApiAuth($user)
            ->getJson('/api/admissions/reference?catalogs=students_statuses')
            ->assertUnprocessable();
    }

    /**
     * Проверяет RBAC-запрет для пользователя без admissions.reference.view.
     */
    public function test_user_without_permission_cannot_read_admission_references(): void
    {
        $this->seed(RoleSeeder::class);
        $student = $this->createApiUser(roleCode: 'student');

        $this->withApiAuth($student)
            ->getJson('/api/admissions/reference')
            ->assertForbidden();
    }

    /**
     * Проверяет, что API не предоставляет write-methods.
     */
    public function test_admission_reference_api_is_get_only(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->createApiUser(roleCode: 'admin');

        $this->withApiAuth($admin)->postJson('/api/admissions/reference')->assertStatus(405);
        $this->withApiAuth($admin)->putJson('/api/admissions/reference/applicant_document_types')->assertStatus(405);
        $this->withApiAuth($admin)->deleteJson('/api/admissions/reference/applicant_document_types')->assertStatus(405);
    }

    /**
     * Проверяет поведение фильтра активных и неактивных элементов.
     */
    public function test_inactive_items_are_hidden_by_default_and_available_by_flag(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(AdmissionReferenceSeeder::class);

        $catalog = ReferenceCatalog::where('code', 'admission_sources')->firstOrFail();
        ReferenceItem::create([
            'catalog_id' => $catalog->id,
            'code' => 'legacy_closed_source',
            'name' => 'Закрытый legacy-источник',
            'sort_order' => 999,
            'is_active' => false,
            'metadata' => ['is_system' => false],
        ]);

        $user = $this->createApiUser(roleCode: 'admission');

        $this->withApiAuth($user)
            ->getJson('/api/admissions/reference/admission_sources')
            ->assertOk()
            ->assertJsonMissing(['code' => 'legacy_closed_source']);

        $this->withApiAuth($user)
            ->getJson('/api/admissions/reference/admission_sources?active_only=false')
            ->assertOk()
            ->assertJsonFragment(['code' => 'legacy_closed_source']);
    }

    /**
     * Проверяет, что обычное чтение справочников не засоряет Audit Log.
     */
    public function test_reference_reads_do_not_create_audit_noise(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(AdmissionReferenceSeeder::class);

        $user = $this->createApiUser(roleCode: 'admission');
        $before = AuditLog::count();

        $this->withApiAuth($user)
            ->getJson('/api/admissions/reference/applicant_document_types')
            ->assertOk();

        $this->assertSame($before, AuditLog::count());
    }
}
