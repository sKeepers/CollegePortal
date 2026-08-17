<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Teacher;
use App\Services\PersonService;
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

    /**
     * Карточка преподавателя без человека — сирота: в «Людях» её не видно, и
     * зеркало общих данных до неё не достаёт, потому что ищет по `person_id`.
     * На боевом портале так и вышло: две карточки, заведённые через API, не
     * попали ни к одному человеку.
     */
    public function test_it_creates_person_for_new_teacher(): void
    {
        $this->postJson('/api/teachers', [
            'last_name' => 'Sidorova',
            'first_name' => 'Maria',
            'middle_name' => 'Ivanovna',
            'phone' => '+7 900 000 0001',
            'email' => 'teacher@example.test',
        ])->assertCreated();

        $teacher = Teacher::query()->where('email', 'teacher@example.test')->firstOrFail();

        $this->assertNotNull($teacher->person_id, 'Карточка преподавателя осталась без человека.');
        $this->assertDatabaseHas('people', [
            'id' => $teacher->person_id,
            'last_name' => 'Sidorova',
            'first_name' => 'Maria',
        ]);
    }

    /**
     * Второй карточки человека для того же человека быть не должно: совпадение
     * по телефону, почте или СНИЛС означает, что он уже заведён.
     */
    public function test_it_links_teacher_to_existing_person(): void
    {
        // Через службу, а не `Person::create`: телефон хранится цифрами, и
        // фикстура, набранная руками, разошлась бы с тем, что пишет портал.
        $person = app(PersonService::class)->createPerson([
            'last_name' => 'Sidorova',
            'first_name' => 'Maria',
            'phone' => '+7 900 000 0001',
            'status' => 'active',
        ]);

        $this->postJson('/api/teachers', [
            'last_name' => 'Sidorova',
            'first_name' => 'Maria',
            'phone' => '+7 900 000 0001',
        ])->assertCreated();

        $teacher = Teacher::query()->where('last_name', 'Sidorova')->firstOrFail();

        $this->assertSame($person->id, $teacher->person_id);
        $this->assertSame(1, Person::query()->where('last_name', 'Sidorova')->count());
    }

    /**
     * Когда подходящих карточек несколько, угадывать нельзя: портал отказывает и
     * просит указать человека явно — тем же полем, которым его можно указать сразу.
     */
    public function test_it_refuses_to_guess_between_several_people(): void
    {
        $people = app(PersonService::class);
        $people->createPerson(['last_name' => 'Sidorova', 'first_name' => 'Maria', 'phone' => '+7 900 000 0001', 'status' => 'active']);
        $people->createPerson(['last_name' => 'Sidorova', 'first_name' => 'Maria', 'email' => 'teacher@example.test', 'status' => 'active']);

        $this->postJson('/api/teachers', [
            'last_name' => 'Sidorova',
            'first_name' => 'Maria',
            'phone' => '+7 900 000 0001',
            'email' => 'teacher@example.test',
        ])->assertStatus(422)->assertJsonValidationErrors('person_id');

        $this->assertSame(0, Teacher::query()->count());
    }

    public function test_it_uses_person_named_in_the_request(): void
    {
        $person = app(PersonService::class)->createPerson(['last_name' => 'Petrova', 'first_name' => 'Olga', 'status' => 'active']);

        $this->postJson('/api/teachers', [
            'person_id' => $person->id,
            'last_name' => 'Sidorova',
            'first_name' => 'Maria',
        ])->assertCreated();

        $teacher = Teacher::query()->firstOrFail();

        $this->assertSame($person->id, $teacher->person_id);
        // Карточка правит человека, а не заводит второго: так же работает правка.
        $this->assertDatabaseHas('people', ['id' => $person->id, 'last_name' => 'Sidorova']);
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
