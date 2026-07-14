<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GeneratedDocument;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentEngineApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_does_not_create_document_and_generation_supports_public_verification(): void
    {
        $user = $this->userWithPermissions([
            'documents.view',
            'documents.create',
            'documents.generate',
            'documents.download_docx',
        ]);
        $student = $this->studentFixture();
        $this->documentSettings();

        $this->withApiAuth($user)->postJson('/api/documents/preview', [
            'document_type_code' => 'student_enrollment_certificate',
            'student_id' => $student->id,
        ])
            ->assertOk()
            ->assertJsonPath('can_generate', true);

        $this->assertDatabaseCount('generated_documents', 0);

        $documentId = $this->withApiAuth($user)->postJson('/api/documents/generate', [
            'document_type_code' => 'student_enrollment_certificate',
            'student_id' => $student->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.subject_type', 'student')
            ->json('data.id');

        $document = GeneratedDocument::query()->findOrFail($documentId);

        $this->assertDatabaseHas('document_events', [
            'generated_document_id' => $document->id,
            'event_type' => 'generated',
        ]);
        $this->assertDatabaseHas('audit_logs', ['module' => 'documents', 'action' => 'generated']);

        $this->getJson('/api/public/documents/'.$document->verification_public_id.'/verify')
            ->assertOk()
            ->assertJsonPath('registration_number', $document->registration_number)
            ->assertJsonMissing(['subject' => 'Иванов Иван Иванович']);
    }

    public function test_document_generation_requires_permission(): void
    {
        $student = $this->studentFixture();
        $this->documentSettings();

        $this->withApiAuth(User::factory()->create(['is_active' => true]))
            ->postJson('/api/documents/generate', [
                'document_type_code' => 'student_enrollment_certificate',
                'student_id' => $student->id,
            ])
            ->assertForbidden();
    }

    private function studentFixture(): Student
    {
        $group = Group::query()->create([
            'name' => '1-ИО',
            'specialty' => 'Инструментальное исполнительство',
            'course' => 1,
            'year_start' => 2026,
        ]);

        return Student::query()->create([
            'group_id' => $group->id,
            'last_name' => 'Иванов',
            'first_name' => 'Иван',
            'middle_name' => 'Иванович',
            'course' => 1,
            'status' => 'active',
            'education_form' => 'очная',
            'funding_form' => 'бюджет',
        ]);
    }

    private function documentSettings(): void
    {
        foreach ([
            ['organization', 'full_name', 'ГБПОУ Тестовый колледж'],
            ['organization', 'short_name', 'Тестовый колледж'],
            ['organization', 'head_full_name', 'Петров Петр Петрович'],
            ['organization', 'head_position', 'Директор'],
        ] as [$group, $key, $value]) {
            Setting::query()->updateOrCreate(
                ['group' => $group, 'key' => $key],
                ['value' => $value, 'type' => 'string', 'is_public' => false],
            );
        }
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::query()->firstOrCreate(['code' => 'documents_test'], ['name' => 'Documents Test']);
        $ids = collect($permissions)->map(fn (string $permission) => Permission::query()->firstOrCreate(
            ['code' => $permission],
            ['name' => $permission, 'module' => 'Documents', 'description' => null, 'system' => true, 'active' => true],
        )->id);
        $role->permissions()->sync($ids);

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }
}
