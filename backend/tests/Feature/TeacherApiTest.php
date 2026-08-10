<?php

namespace Tests\Feature;

use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_it_lists_teachers(): void
    {
        Teacher::create([
            'last_name' => 'Petrov',
            'first_name' => 'Alexey',
            'department' => 'Music Department',
        ]);

        $this->getJson('/api/teachers?search=petrov')
            ->assertOk()
            ->assertJsonPath('data.0.last_name', 'Petrov');
    }

    public function test_it_creates_teacher(): void
    {
        $response = $this->postJson('/api/teachers', [
            'last_name' => 'Sidorova',
            'first_name' => 'Maria',
            'middle_name' => 'Ivanovna',
            'email' => 'teacher@example.test',
            'position' => 'Teacher',
            'department' => 'Design Department',
            'is_active' => true,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.last_name', 'Sidorova');

        $this->assertDatabaseHas('teachers', ['email' => 'teacher@example.test']);
    }

    public function test_it_updates_teacher(): void
    {
        $teacher = Teacher::create([
            'last_name' => 'Petrov',
            'first_name' => 'Alexey',
            'is_active' => true,
        ]);

        $this->patchJson("/api/teachers/{$teacher->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_it_deletes_teacher(): void
    {
        $teacher = Teacher::create([
            'last_name' => 'Petrov',
            'first_name' => 'Alexey',
        ]);

        $this->deleteJson("/api/teachers/{$teacher->id}")
            ->assertNoContent();

        // Удаление не окончательное: карточка уходит в корзину.
        $this->assertNull(Teacher::query()->find($teacher->id));
        $this->assertNotNull(Teacher::withTrashed()->find($teacher->id));
    }
}
