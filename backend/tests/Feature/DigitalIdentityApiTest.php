<?php

namespace Tests\Feature;

use App\Models\DigitalIdentity;
use App\Models\Group;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DigitalIdentityApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_it_issues_digital_pass_for_student(): void
    {
        $student = $this->createStudent();

        $response = $this->postJson('/api/digital-identities/issue', [
            'entity_type' => 'student',
            'entity_id' => $student->id,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.entity_type', 'student')
            ->assertJsonPath('data.entity_id', $student->id)
            ->assertJsonPath('data.status', DigitalIdentity::STATUS_ACTIVE)
            ->assertJsonPath('data.owner.last_name', 'Иванов');

        $this->assertTrue(Str::isUuid($response->json('data.token')));
    }

    public function test_it_issues_digital_pass_for_teacher_and_revokes_it(): void
    {
        $teacher = Teacher::create([
            'last_name' => 'Петров',
            'first_name' => 'Алексей',
            'phone' => '+79990000002',
            'email' => 'teacher@example.test',
            'department' => 'Музыкальное отделение',
        ]);

        $identityId = $this->postJson('/api/digital-identities/issue', [
            'entity_type' => 'teacher',
            'entity_id' => $teacher->id,
        ])
            ->assertCreated()
            ->json('data.id');

        $this->postJson("/api/digital-identities/{$identityId}/revoke")
            ->assertOk()
            ->assertJsonPath('data.status', DigitalIdentity::STATUS_REVOKED);

        $this->assertDatabaseHas('digital_identities', [
            'id' => $identityId,
            'status' => DigitalIdentity::STATUS_REVOKED,
        ]);
    }

    public function test_qr_svg_does_not_expose_personal_data(): void
    {
        $student = $this->createStudent();

        $identityId = $this->postJson('/api/digital-identities/issue', [
            'entity_type' => 'student',
            'entity_id' => $student->id,
        ])->json('data.id');

        $svg = $this->get("/api/digital-identities/{$identityId}/qr")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->getContent();

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringNotContainsString('Иванов', $svg);
        $this->assertStringNotContainsString('Дмитрий', $svg);
        $this->assertStringNotContainsString('student@example.test', $svg);
        $this->assertStringNotContainsString('+79990000001', $svg);
    }



    public function test_qr_svg_and_png_are_plain_token_payloads_without_personal_data(): void
    {
        $student = $this->createStudent();

        $response = $this->postJson('/api/digital-identities/issue', [
            'entity_type' => 'student',
            'entity_id' => $student->id,
        ])->assertCreated();

        $identityId = $response->json('data.id');
        $token = $response->json('data.token');

        $svg = $this->get("/api/digital-identities/{$identityId}/qr?format=svg")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml; charset=UTF-8')
            ->assertHeader('X-QR-Content', 'token')
            ->getContent();

        $png = $this->get("/api/digital-identities/{$identityId}/qr?format=png")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-QR-Content', 'token')
            ->getContent();

        $this->assertMatchesRegularExpression('/^[\x21-\x7E]+$/', $token);
        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
        $this->assertStringContainsString('fill="#000000"', $svg);
        $this->assertStringContainsString('fill="#ffffff"', $svg);

        foreach (['Иванов', 'Дмитрий', 'student@example.test', '+79990000001'] as $personalData) {
            $this->assertStringNotContainsString($personalData, $svg);
            $this->assertStringNotContainsString($personalData, $png);
        }
    }

    private function createStudent(): Student
    {
        $group = Group::create([
            'name' => 'ИСП-101',
            'specialty' => 'Инструментальное исполнительство',
            'course' => 1,
            'year_start' => 2026,
        ]);

        return Student::create([
            'group_id' => $group->id,
            'last_name' => 'Иванов',
            'first_name' => 'Дмитрий',
            'phone' => '+79990000001',
            'email' => 'student@example.test',
            'status' => 'active',
        ]);
    }
}
