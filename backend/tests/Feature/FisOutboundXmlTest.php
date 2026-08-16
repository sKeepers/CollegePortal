<?php

namespace Tests\Feature;

use App\Models\Admissions\AdmissionApplication;
use App\Models\Admissions\Applicant;
use App\Models\Admissions\ApplicationDocumentSet;
use App\Models\Admissions\EducationDocument;
use App\Models\Admissions\IdentityDocument;
use App\Models\Admissions\ProgramChoice;
use App\Models\EducationProgram;
use App\Models\FisExternalMapping;
use App\Models\FisOutboundPackage;
use App\Models\Permission;
use App\Models\Person;
use App\Models\ReferenceCatalog;
use App\Models\ReferenceItem;
use App\Models\Role;
use App\Models\Specialty;
use App\Models\User;
use App\Services\FisIntegration\Xml\FisXsdSchema;
use Database\Seeders\RoleSeeder;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Сборка пакета по официальной XSD ФИС ГИА и Приёма 4.9.
 *
 * Проверка идёт по настоящему файлу схемы из resources, а не по упрощённой
 * копии: смысл этих тестов в том, что портал выдаёт документ, который принимает
 * официальная схема, а не тот, который принимает наша выдумка о ней.
 */
class FisOutboundXmlTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_xsd_compiles_after_compatibility_fixes(): void
    {
        $schema = app(FisXsdSchema::class);

        $this->assertTrue($schema->loaded(), 'Официальная XSD не найдена в resources.');

        $source = $schema->source();
        $this->assertStringNotContainsString('(?!', $source);
        $this->assertSame([
            'value="(^(?!\s*$).+)?"' => 1,
            'value="^(?!\s*$).+"' => 6,
        ], $schema->compatibilityNotes());

        // Тот же документ по неисправленному файлу не проверяется вовсе:
        // libxml отказывается компилировать схему целиком.
        $document = new DOMDocument();
        $document->loadXML('<?xml version="1.0" encoding="UTF-8"?><PackageData><InstitutionPrograms><InstitutionProgram><UID>program-1</UID><Name>Демонстрационная программа</Name></InstitutionProgram></InstitutionPrograms></PackageData>');

        $previous = libxml_use_internal_errors(true);
        try {
            $rawResult = $document->schemaValidate((string) $schema->path());
        } catch (\Throwable) {
            // libxml сообщает о несобираемой схеме предупреждением, которое
            // тестовый обработчик превращает в исключение. Нам важен сам факт.
            $rawResult = false;
        }
        $this->assertFalse($rawResult, 'Официальный файл вдруг стал компилироваться — слой совместимости больше не нужен.');
        $this->assertTrue($document->schemaValidateSource($source));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }

    public function test_institution_programs_package_is_generated_and_passes_official_xsd(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->userWith(['fis.outbound.view', 'fis.outbound.create', 'fis.outbound.generate', 'fis.outbound.validate', 'fis.outbound.download']));
        $this->createProgram();

        $package = FisOutboundPackage::create([
            'package_type' => 'institution-programs',
            'schema_version' => config('fis_api.schema_version'),
            'environment' => 'test',
            'status' => 'draft',
        ]);

        $this->postJson("/api/fis/outbound/packages/{$package->id}/generate")
            ->assertOk()
            ->assertJsonPath('data.status', 'generated')
            ->assertJsonPath('data.schema_version', '4.9');

        $this->postJson("/api/fis/outbound/packages/{$package->id}/validate")
            ->assertOk()
            ->assertJsonPath('validation.ok', true);

        $xml = $this->get("/api/fis/outbound/packages/{$package->id}/download")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->getContent();

        $this->assertSame($xml, Storage::disk('local')->get($package->fresh()->payload_path));
        $this->assertStringContainsString('<PackageData>', $xml);
        $this->assertStringContainsString('<Code>53.02.03</Code>', $xml);
    }

    public function test_application_package_is_generated_and_passes_official_xsd(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->userWith(['fis.outbound.view', 'fis.outbound.create', 'fis.outbound.generate', 'fis.outbound.validate']));
        $this->configureDictionaries();
        $application = $this->createApplicationFixture();
        $this->mapApplicationStatus($application);
        $this->mapCompetitiveGroup($application->choices()->first()->education_program_id);

        $package = FisOutboundPackage::create([
            'package_type' => 'applications',
            'admission_year' => 2026,
            'schema_version' => config('fis_api.schema_version'),
            'environment' => 'test',
            'status' => 'draft',
        ]);

        $this->postJson("/api/fis/outbound/packages/{$package->id}/generate")->assertOk();
        $this->postJson("/api/fis/outbound/packages/{$package->id}/validate")
            ->assertOk()
            ->assertJsonPath('validation.ok', true);

        $xml = Storage::disk('local')->get($package->fresh()->payload_path);
        $this->assertStringContainsString('<ApplicationNumber>2026-001</ApplicationNumber>', $xml);
        $this->assertStringContainsString('<CompetitiveGroupUID>competition-2026-1</CompetitiveGroupUID>', $xml);
        $this->assertStringContainsString('<SchoolCertificateBasicDocument>', $xml);
        $this->assertStringContainsString('<After11>false</After11>', $xml);
        // СНИЛС в пакете нужен целиком: это официальная выгрузка, а не отчёт.
        $this->assertStringContainsString('<SNILS>112-233-445 95</SNILS>', $xml);
    }

    public function test_missing_references_block_generation_and_are_reported_together(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->userWith(['fis.outbound.view', 'fis.outbound.create', 'fis.outbound.generate']));
        $this->createApplicationFixture();

        $package = FisOutboundPackage::create([
            'package_type' => 'applications',
            'admission_year' => 2026,
            'schema_version' => config('fis_api.schema_version'),
            'environment' => 'test',
            'status' => 'draft',
        ]);

        $response = $this->postJson("/api/fis/outbound/packages/{$package->id}/generate")->assertStatus(409);

        $codes = collect($response->json('blockers'))->pluck('code')->all();
        $fields = collect($response->json('blockers'))->pluck('field')->all();

        // Список приходит целиком, а не по одной причине за раз.
        $this->assertContains('competitive_group_missing', $codes);
        $this->assertContains('GenderID', $fields);
        $this->assertContains('StatusID', $fields);
        $this->assertNull($package->fresh()->payload_path);
        $this->assertSame('generation_blocked', $package->events()->latest('id')->first()->event_type);
    }

    public function test_gia_results_package_is_refused_with_explanation(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->userWith(['fis.outbound.view', 'fis.outbound.create', 'fis.outbound.generate']));

        $package = FisOutboundPackage::create([
            'package_type' => 'gia',
            'schema_version' => config('fis_api.schema_version'),
            'environment' => 'test',
            'status' => 'draft',
        ]);

        $this->postJson("/api/fis/outbound/packages/{$package->id}/generate")
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'Метод импорта ФИС ГИА и Приёма 4.9 принимает сведения приёмной кампании: кампании, объём приёма, конкурсы, образовательные программы, заявления и приказы. Раздела для результатов ГИА колледжа в схеме нет — «ГИА» в названии системы относится к ЕГЭ и ОГЭ, которые в неё вносит РЦОИ. Пакет ГИА портала остаётся внутренним отчётом и в этот метод не отправляется.']);
    }

    public function test_spec_info_reports_the_official_schema_as_loaded(): void
    {
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->userWith(['fis.outbound.view']));

        $this->getJson('/api/fis/outbound/spec-info')
            ->assertOk()
            ->assertJsonPath('schema_version', '4.9')
            ->assertJsonPath('xsd_loaded', true)
            ->assertJsonPath('official_schema_loaded', true)
            ->assertJsonPath('manifest.version', '4.9')
            ->assertJsonPath('supported_package_types', ['institution-programs', 'applications']);
    }

    private function configureDictionaries(): void
    {
        config(['fis_api.dictionaries.gender' => ['male' => 1, 'female' => 2]]);
    }

    private function createProgram(): EducationProgram
    {
        $specialty = Specialty::query()->create(['code' => '53.02.03', 'name' => 'Инструментальное исполнительство']);

        return EducationProgram::query()->create([
            'specialty_id' => $specialty->id,
            'name' => 'Инструментальное исполнительство (фортепиано)',
            'year_start' => 2026,
            'study_form' => 'Очная',
            'is_active' => true,
        ]);
    }

    private function createApplicationFixture(): AdmissionApplication
    {
        $program = $this->createProgram();
        $person = Person::query()->create([
            'last_name' => 'Демонстрационный',
            'first_name' => 'Абитуриент',
            'middle_name' => 'Тестович',
            'gender' => 'male',
            'birth_date' => '2009-05-14',
            'place_birth' => 'Сыктывкар',
            'email' => 'demo@example.test',
            'snils' => '112-233-445 95',
            'snils_hash' => hash('sha256', '11223344595'),
            'status' => 'active',
        ]);
        $applicant = Applicant::query()->create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'status_id' => $this->referenceItemId('applicant_statuses', 'active'),
            'source_id' => $this->referenceItemId('admission_sources', 'manual'),
        ]);
        $statusId = $this->referenceItemId('admission_application_statuses', AdmissionApplication::STATUS_REGISTERED);

        $application = AdmissionApplication::query()->create([
            'uuid' => (string) Str::uuid(),
            'applicant_id' => $applicant->id,
            'person_id' => $person->id,
            'admission_year' => 2026,
            'application_number' => '2026-001',
            'education_program_id' => $program->id,
            'last_name' => $person->last_name,
            'first_name' => $person->first_name,
            'middle_name' => $person->middle_name,
            'birth_date' => $person->birth_date,
            'email' => $person->email,
            'education_base' => 'after_9',
            'status' => AdmissionApplication::STATUS_REGISTERED,
            'status_id' => $statusId,
            'source_id' => $this->referenceItemId('admission_sources', 'manual'),
            'submitted_at' => '2026-07-01',
            'registered_at' => '2026-07-01 10:15:00',
        ]);

        ProgramChoice::query()->create([
            'application_id' => $application->id,
            'priority' => 1,
            'specialty_id' => $program->specialty_id,
            'education_program_id' => $program->id,
            'is_primary' => true,
        ]);

        $identity = IdentityDocument::query()->create([
            'uuid' => (string) Str::uuid(),
            'applicant_id' => $applicant->id,
            'person_id' => $person->id,
            'document_type_id' => $this->referenceItemId('admission_identity_document_types', 'russian_passport'),
            'series' => '8712',
            'number' => '654321',
            'issue_date' => '2025-06-01',
            'issued_by' => 'МВД по Республике Коми',
            'subdivision_code' => '110-002',
            'release_place' => 'Сыктывкар',
            'is_primary' => true,
            'verification_status' => IdentityDocument::STATUS_VERIFIED,
            'fis_identity_document_type_id' => 1,
            'fis_nationality_type_id' => 1,
            'fis_release_country_id' => 1,
        ]);

        $education = EducationDocument::query()->create([
            'uuid' => (string) Str::uuid(),
            'applicant_id' => $applicant->id,
            'person_id' => $person->id,
            'document_type_id' => $this->referenceItemId('admission_education_document_types', 'basic_general_certificate'),
            'number' => '112233',
            'issue_date' => '2026-06-20',
            'document_organization' => 'Демонстрационная школа №1',
            'graduation_year' => 2026,
            'average_score' => 4.5,
            'is_primary' => true,
            'verification_status' => EducationDocument::STATUS_VERIFIED,
            'fis_region_id' => 11,
        ]);

        ApplicationDocumentSet::query()->create([
            'application_id' => $application->id,
            'identity_document_id' => $identity->id,
            'education_document_id' => $education->id,
            'linked_at' => now(),
        ]);

        return $application->fresh(['choices']);
    }

    private function mapApplicationStatus(AdmissionApplication $application): void
    {
        FisExternalMapping::query()->create([
            'entity_type' => ReferenceItem::class,
            'entity_id' => $application->status_id,
            'external_type' => 'fis:ApplicationStatusID',
            'external_id' => '2',
            'environment' => 'test',
        ]);
    }

    private function mapCompetitiveGroup(int $educationProgramId): void
    {
        FisExternalMapping::query()->create([
            'entity_type' => EducationProgram::class,
            'entity_id' => $educationProgramId,
            'external_type' => 'fis:CompetitiveGroupUID',
            'external_id' => 'competition-2026-1',
            'environment' => 'test',
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

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['code' => 'fis_xml_'.md5(json_encode($permissions))], ['name' => 'FIS XML '.substr(md5(json_encode($permissions)), 0, 8), 'description' => 'Test role']);
        foreach ($permissions as $code) {
            $permission = Permission::firstOrCreate(['code' => $code], ['name' => $code, 'module' => 'Test', 'description' => $code, 'system' => true, 'active' => true]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->attach($role->id);

        return $user;
    }
}
