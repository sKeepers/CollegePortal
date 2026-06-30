<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubjectApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_it_lists_subjects(): void
    {
        Subject::create([
            'name' => 'Music Theory',
            'code' => 'MUS-101',
            'department' => 'Music Department',
        ]);

        $this->getJson('/api/subjects?search=music')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'MUS-101');
    }

    public function test_it_creates_subject_with_teachers(): void
    {
        $teacher = Teacher::create([
            'last_name' => 'Petrov',
            'first_name' => 'Alexey',
        ]);

        $response = $this->postJson('/api/subjects', [
            'name' => 'Music Theory',
            'code' => 'MUS-101',
            'department' => 'Music Department',
            'teacher_ids' => [$teacher->id],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Music Theory')
            ->assertJsonPath('data.teachers.0.id', $teacher->id);

        $this->assertDatabaseHas('subject_teacher', [
            'teacher_id' => $teacher->id,
        ]);
    }

    public function test_it_updates_subject_teachers(): void
    {
        $teacher = Teacher::create([
            'last_name' => 'Petrov',
            'first_name' => 'Alexey',
        ]);
        $subject = Subject::create([
            'name' => 'Music Theory',
            'code' => 'MUS-101',
        ]);

        $this->patchJson("/api/subjects/{$subject->id}", [
            'teacher_ids' => [$teacher->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.teachers.0.id', $teacher->id);
    }

    public function test_it_deletes_subject(): void
    {
        $subject = Subject::create([
            'name' => 'Music Theory',
            'code' => 'MUS-101',
        ]);

        $this->deleteJson("/api/subjects/{$subject->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('subjects', ['id' => $subject->id]);
    }
}
