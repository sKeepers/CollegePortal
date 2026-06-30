<?php

namespace Tests\Feature;

use App\Models\Classroom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ClassroomCsvApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_it_exports_classrooms_to_csv(): void
    {
        Classroom::create([
            'number' => '305',
            'building' => 'Главный корпус',
            'floor' => 3,
            'capacity' => 18,
            'type' => 'Класс фортепиано',
        ]);

        $response = $this->get('/api/classrooms/export');

        $response
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();

        $this->assertStringContainsString('number', $content);
        $this->assertStringContainsString('305', $content);
        $this->assertStringContainsString('Класс фортепиано', $content);
    }

    public function test_it_imports_classrooms_from_csv(): void
    {
        $existing = Classroom::create([
            'number' => '305',
            'building' => 'Главный корпус',
            'floor' => 3,
            'capacity' => 18,
        ]);

        $csv = implode("\n", [
            'id;number;building;floor;capacity;type;description',
            "{$existing->id};305;Главный корпус;3;20;Класс фортепиано;Аудитория для индивидуальных занятий",
            ';401;Главный корпус;4;30;Лекционная аудитория;',
        ]);

        $file = UploadedFile::fake()->createWithContent('classrooms.csv', $csv);

        $response = $this->post('/api/classrooms/import', [
            'file' => $file,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.errors', []);

        $this->assertDatabaseHas('classrooms', [
            'id' => $existing->id,
            'capacity' => 20,
            'type' => 'Класс фортепиано',
        ]);
        $this->assertDatabaseHas('classrooms', [
            'number' => '401',
            'building' => 'Главный корпус',
            'capacity' => 30,
        ]);
    }

    public function test_it_returns_line_errors_for_invalid_classroom_rows(): void
    {
        $csv = implode("\n", [
            'id;number;building;floor;capacity;type;description',
            ';305;Главный корпус;60;0;Лекционная аудитория;',
        ]);

        $file = UploadedFile::fake()->createWithContent('classrooms.csv', $csv);

        $response = $this->post('/api/classrooms/import', [
            'file' => $file,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.errors.0.line', 2);

        $this->assertDatabaseMissing('classrooms', [
            'number' => '305',
        ]);
    }
}
