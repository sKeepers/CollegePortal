<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Аудит 08.08.2026, находка 6: справочники специальностей и образовательных
 * программ были доступны только через API — экрана не было, и завести новую
 * специальность из портала было нельзя.
 *
 * Экраны появились. Тест закрепляет условие, при котором они работают: роль,
 * которой показан пункт меню (`reference.manage`), должна доходить до всех
 * запросов этих экранов, включая создание, изменение и удаление. Если право
 * маршрута разъедется с правом пункта меню, тест скажет об этом раньше
 * пользователя.
 */
class ReferenceRegistryScreenAccessTest extends TestCase
{
    use RefreshDatabase;

    private function tokenForRoleWithReferenceManage(): string
    {
        $this->seed(RoleSeeder::class);

        // director — роль из сидера, у которой есть reference.manage и нет admin.
        $role = Role::where('code', 'director')->firstOrFail();
        $this->assertContains('reference.manage', $role->permissions()->pluck('code')->all());

        $token = Str::random(80);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'is_active' => true,
            'api_token_hash' => Hash::make($token),
            'api_token_lookup_hash' => hash('sha256', $token),
            'api_token_expires_at' => now()->addMinutes(720),
        ]);
        $user->roles()->sync([$role->id]);

        return $token;
    }

    public function test_reference_manage_role_can_run_the_whole_specialty_screen(): void
    {
        $token = $this->tokenForRoleWithReferenceManage();
        $this->withHeader('Authorization', "Bearer {$token}");

        $this->getJson('/api/specialties')->assertOk();

        $created = $this->postJson('/api/specialties', [
            'name' => 'Инструментальное исполнительство',
            'education_level' => 'СПО',
            'qualification' => 'Артист',
            'normative_study_years' => 4,
        ])->assertCreated();

        $id = $created->json('data.id');

        // Код не задан — его подставляет AutoCodeService, поэтому форма и
        // позволяет оставить поле пустым.
        $this->assertNotEmpty($created->json('data.code'));

        $this->putJson("/api/specialties/{$id}", [
            'name' => 'Инструментальное исполнительство',
            'education_level' => 'СПО',
            'qualification' => 'Артист оркестра',
            'normative_study_years' => 4,
        ])->assertOk();

        $this->get('/api/specialties/export')->assertOk();
        $this->deleteJson("/api/specialties/{$id}")->assertNoContent();
    }

    public function test_reference_manage_role_can_run_the_whole_education_program_screen(): void
    {
        $token = $this->tokenForRoleWithReferenceManage();
        $this->withHeader('Authorization', "Bearer {$token}");

        $specialty = Specialty::create([
            'code' => '53.02.03',
            'name' => 'Инструментальное исполнительство',
            'education_level' => 'СПО',
            'qualification' => 'Артист',
            'normative_study_years' => 4,
        ]);

        $this->getJson('/api/education-programs')->assertOk();

        $created = $this->postJson('/api/education-programs', [
            'specialty_id' => $specialty->id,
            'name' => 'Фортепиано',
            'year_start' => 2026,
            'study_form' => 'Очная',
            'study_years' => 4,
            'is_active' => true,
        ])->assertCreated();

        $id = $created->json('data.id');

        $this->putJson("/api/education-programs/{$id}", [
            'specialty_id' => $specialty->id,
            'name' => 'Фортепиано',
            'year_start' => 2026,
            'study_form' => 'Очная',
            'study_years' => 3.5,
            'is_active' => false,
        ])->assertOk();

        $this->get('/api/education-programs/export')->assertOk();
        $this->deleteJson("/api/education-programs/{$id}")->assertNoContent();
    }

    /** Экран программ показывает специальность в строке — связь должна приходить с ответом. */
    public function test_education_program_list_carries_its_specialty(): void
    {
        $token = $this->tokenForRoleWithReferenceManage();
        $this->withHeader('Authorization', "Bearer {$token}");

        $specialty = Specialty::create([
            'code' => '53.02.03',
            'name' => 'Инструментальное исполнительство',
            'education_level' => 'СПО',
            'qualification' => 'Артист',
            'normative_study_years' => 4,
        ]);

        $this->postJson('/api/education-programs', [
            'specialty_id' => $specialty->id,
            'name' => 'Фортепиано',
            'year_start' => 2026,
            'study_form' => 'Очная',
        ])->assertCreated();

        $this->getJson('/api/education-programs')
            ->assertOk()
            ->assertJsonPath('data.0.specialty.code', '53.02.03');
    }
}
