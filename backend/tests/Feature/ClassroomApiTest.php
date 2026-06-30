<?php

namespace Tests\Feature;

use App\Models\Classroom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassroomApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_it_lists_classrooms(): void
    {
        Classroom::create([
            'number' => '201',
            'building' => 'Main',
            'floor' => 2,
            'capacity' => 24,
            'type' => 'Lecture room',
        ]);

        $this->getJson('/api/classrooms?building=Main')
            ->assertOk()
            ->assertJsonPath('data.0.number', '201');
    }

    public function test_it_creates_classroom(): void
    {
        $response = $this->postJson('/api/classrooms', [
            'number' => '305',
            'building' => 'Main',
            'floor' => 3,
            'capacity' => 18,
            'type' => 'Practice room',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.number', '305');

        $this->assertDatabaseHas('classrooms', ['number' => '305']);
    }

    public function test_it_updates_classroom(): void
    {
        $classroom = Classroom::create([
            'number' => '201',
            'building' => 'Main',
        ]);

        $this->patchJson("/api/classrooms/{$classroom->id}", ['capacity' => 30])
            ->assertOk()
            ->assertJsonPath('data.capacity', 30);
    }

    public function test_it_deletes_classroom(): void
    {
        $classroom = Classroom::create([
            'number' => '201',
            'building' => 'Main',
        ]);

        $this->deleteJson("/api/classrooms/{$classroom->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('classrooms', ['id' => $classroom->id]);
    }
}
