<?php

namespace Tests\Feature;

use App\Models\EducationProgram;
use App\Models\FisExternalMapping;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Specialty;
use App\Models\User;
use App\Services\FisIntegration\FisCompetitiveGroupIntakeService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Приём конкурсов ФИС из сведений об организации.
 *
 * Конкурсы ведёт сама ФИС, а исходящему пакету заявлений нужен их
 * `CompetitiveGroupUID`. Связь читается из ответа напрямую: в
 * `EduPrograms/EduProgram/UID` стоит идентификатор, который портал сам выдаёт
 * в разделе `InstitutionPrograms`.
 */
class FisCompetitiveGroupIntakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_competitive_groups_are_linked_to_education_programs(): void
    {
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->userWith(['fis.outbound.view', 'fis.settings.manage']));
        $piano = $this->createProgram('Фортепиано', '53.02.03');

        $this->post('/api/fis/dictionaries/apply', ['file' => $this->file($this->exportXml($piano->id))])
            ->assertOk()
            ->assertJsonPath('data.kind', 'institution_export')
            ->assertJsonPath('data.mapped', 1)
            ->assertJsonPath('data.ambiguous', [])
            ->assertJsonPath('data.unlinked', []);

        $mapping = FisExternalMapping::query()
            ->where('entity_type', EducationProgram::class)
            ->where('entity_id', $piano->id)
            ->where('external_type', FisCompetitiveGroupIntakeService::EXTERNAL_TYPE)
            ->firstOrFail();

        $this->assertSame('cg-2026-piano', $mapping->external_id);
        $this->assertSame('Фортепиано, очная, бюджет', $mapping->metadata['name']);
        $this->assertSame('campaign-2026', $mapping->metadata['campaign_uid']);
    }

    /**
     * Бюджет и платное — разные условия приёма, и конкурсы у них разные. До
     * 11.08.2026 сопоставление хранило один UID на программу, поэтому такая
     * программа не связывалась вовсе и заявления по ней не отправлялись совсем.
     */
    public function test_a_program_with_two_competitions_keeps_both_by_admission_terms(): void
    {
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->userWith(['fis.outbound.view', 'fis.settings.manage']));
        $piano = $this->createProgram('Фортепиано', '53.02.03');

        $this->post('/api/fis/dictionaries/apply', ['file' => $this->file($this->twoCompetitionsXml($piano->id))])
            ->assertOk()
            ->assertJsonPath('data.mapped', 2)
            ->assertJsonPath('data.ambiguous', []);

        $mappings = FisExternalMapping::query()
            ->where('external_type', FisCompetitiveGroupIntakeService::EXTERNAL_TYPE)
            ->pluck('external_id', 'scope');

        // Область — форма обучения и источник финансирования в значениях ФИС.
        $this->assertSame('cg-2026-budget', $mappings['1|1']);
        $this->assertSame('cg-2026-paid', $mappings['1|3']);
    }

    /** Неразличимое остаётся неразличимым: одна форма, один источник, два конкурса. */
    public function test_two_competitions_on_the_same_terms_are_still_not_guessed(): void
    {
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->userWith(['fis.outbound.view', 'fis.settings.manage']));
        $piano = $this->createProgram('Фортепиано', '53.02.03');

        $xml = str_replace('<EducationSourceID>3</EducationSourceID>', '<EducationSourceID>1</EducationSourceID>', $this->twoCompetitionsXml($piano->id));

        $response = $this->post('/api/fis/dictionaries/apply', ['file' => $this->file($xml)])
            ->assertOk()
            ->assertJsonPath('data.mapped', 0);

        $ambiguous = $response->json('data.ambiguous');
        $this->assertCount(1, $ambiguous);
        $this->assertCount(2, $ambiguous[0]['candidates']);
        $this->assertSame(0, FisExternalMapping::query()->where('external_type', FisCompetitiveGroupIntakeService::EXTERNAL_TYPE)->count());
    }

    public function test_a_competition_for_an_unknown_program_is_reported(): void
    {
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->userWith(['fis.outbound.view', 'fis.settings.manage']));
        $this->createProgram('Фортепиано', '53.02.03');

        $this->post('/api/fis/dictionaries/apply', ['file' => $this->file($this->exportXml(9999))])
            ->assertOk()
            ->assertJsonPath('data.mapped', 0)
            ->assertJsonPath('data.unlinked.0.competitive_group_uid', 'cg-2026-piano');
    }

    public function test_preview_writes_nothing(): void
    {
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->userWith(['fis.outbound.view']));
        $piano = $this->createProgram('Фортепиано', '53.02.03');

        $this->post('/api/fis/dictionaries/preview', ['file' => $this->file($this->exportXml($piano->id))])
            ->assertOk()
            ->assertJsonPath('data.kind', 'institution_export')
            ->assertJsonPath('data.competitive_groups', 1)
            ->assertJsonCount(1, 'data.will_map');

        $this->assertSame(0, FisExternalMapping::query()->count());
    }

    public function test_institution_data_without_competitions_says_so(): void
    {
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->userWith(['fis.outbound.view']));

        $this->post('/api/fis/dictionaries/preview', ['file' => $this->file('<?xml version="1.0" encoding="UTF-8"?><InstitutionExport><InstitutionDetails><UID>oo-1</UID></InstitutionDetails></InstitutionExport>')])
            ->assertStatus(409)
            ->assertJsonPath('message', 'В сведениях об организации нет раздела «CompetitiveGroups»: конкурсов в файле не оказалось.');
    }

    private function exportXml(int $programId): string
    {
        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <InstitutionExport>
          <AdmissionInfo>
            <CompetitiveGroups>
              <CompetitiveGroup>
                <UID>cg-2026-piano</UID>
                <CampaignUID>campaign-2026</CampaignUID>
                <Name>Фортепиано, очная, бюджет</Name>
                <EducationLevelID>2</EducationLevelID>
                <EducationSourceID>1</EducationSourceID>
                <EducationFormID>1</EducationFormID>
                <DirectionID>1234</DirectionID>
                <EduPrograms>
                  <EduProgram><UID>education-program-{$programId}</UID></EduProgram>
                </EduPrograms>
              </CompetitiveGroup>
            </CompetitiveGroups>
          </AdmissionInfo>
        </InstitutionExport>
        XML;
    }

    private function twoCompetitionsXml(int $programId): string
    {
        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <InstitutionExport>
          <AdmissionInfo>
            <CompetitiveGroups>
              <CompetitiveGroup>
                <UID>cg-2026-budget</UID>
                <Name>Фортепиано, очная, бюджет</Name>
                <EducationSourceID>1</EducationSourceID>
                <EducationFormID>1</EducationFormID>
                <EduPrograms>
                  <EduProgram><UID>education-program-{$programId}</UID></EduProgram>
                </EduPrograms>
              </CompetitiveGroup>
              <CompetitiveGroup>
                <UID>cg-2026-paid</UID>
                <Name>Фортепиано, очная, полное возмещение</Name>
                <EducationSourceID>3</EducationSourceID>
                <EducationFormID>1</EducationFormID>
                <EduPrograms>
                  <EduProgram><UID>education-program-{$programId}</UID></EduProgram>
                </EduPrograms>
              </CompetitiveGroup>
            </CompetitiveGroups>
          </AdmissionInfo>
        </InstitutionExport>
        XML;
    }

    private function createProgram(string $name, string $code): EducationProgram
    {
        $specialty = Specialty::query()->create(['code' => $code, 'name' => $name]);

        return EducationProgram::query()->create([
            'specialty_id' => $specialty->id,
            'name' => $name,
            'year_start' => 2026,
            'study_form' => 'Очная',
        ]);
    }

    private function file(string $xml): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('institution.xml', $xml);
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'Конкурсы '.substr(md5(json_encode($permissions)), 0, 8),
            'code' => 'cg_'.substr(md5(json_encode($permissions)), 0, 12),
            'description' => 'Test role',
        ]);

        foreach ($permissions as $code) {
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                ['name' => $code, 'module' => 'Test', 'description' => $code, 'system' => true, 'active' => true],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->roles()->attach($role->id);

        return $user;
    }
}
