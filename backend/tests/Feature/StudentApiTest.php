<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Person;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_it_lists_students_with_group_filter(): void
    {
        $group = $this->createGroup('M-101');
        $otherGroup = $this->createGroup('D-101');

        Student::create([
            'group_id' => $group->id,
            'last_name' => 'Ivanov',
            'first_name' => 'Dmitry',
            'status' => 'active',
        ]);
        Student::create([
            'group_id' => $otherGroup->id,
            'last_name' => 'Sokolova',
            'first_name' => 'Anna',
            'status' => 'active',
        ]);

        $response = $this->getJson("/api/students?group_id={$group->id}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.last_name', 'Ivanov');
    }

    public function test_it_lists_students_with_search_and_status_filters(): void
    {
        $group = $this->createGroup('M-101');

        Student::create([
            'group_id' => $group->id,
            'last_name' => 'Ivanov',
            'first_name' => 'Dmitry',
            'status' => 'active',
        ]);
        Student::create([
            'group_id' => $group->id,
            'last_name' => 'Sokolova',
            'first_name' => 'Anna',
            'status' => 'academic_leave',
        ]);

        $this->getJson('/api/students?search=sokol&status=academic_leave')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.last_name', 'Sokolova');
    }

    public function test_it_lists_students_with_cyrillic_search(): void
    {
        $group = $this->createGroup('M-101');

        Student::create([
            'group_id' => $group->id,
            'last_name' => 'Соколова',
            'first_name' => 'Валерия',
            'status' => 'active',
        ]);
        Student::create([
            'group_id' => $group->id,
            'last_name' => 'Иванов',
            'first_name' => 'Дмитрий',
            'status' => 'active',
        ]);

        $this->getJson('/api/students?search=Соколова')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.last_name', 'Соколова');
    }

    public function test_it_creates_student(): void
    {
        $group = $this->createGroup('M-101');

        $response = $this->postJson('/api/students', [
            'group_id' => $group->id,
            'last_name' => 'Ivanov',
            'first_name' => 'Dmitry',
            'middle_name' => 'Sergeevich',
            'birth_date' => '2009-05-12',
            'phone' => '+79990000002',
            'email' => 'student@example.test',
            'snils' => '112-233-445 95',
            'status' => 'active',
            'enrollment_date' => '2026-09-01',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.last_name', 'Ivanov')
            ->assertJsonPath('data.group_id', $group->id);

        $this->assertDatabaseHas('students', ['email' => 'student@example.test']);
        $this->assertDatabaseHas('people', ['snils_hash' => hash('sha256', '11223344595')]);
    }

    public function test_it_rejects_invalid_student_snils(): void
    {
        $group = $this->createGroup('M-101');

        $this->postJson('/api/students', ['group_id' => $group->id, 'last_name' => 'Ivanov', 'first_name' => 'Dmitry', 'status' => 'active', 'snils' => '112-233-445 96'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('snils');
    }

    public function test_it_reuses_person_by_normalized_snils_hash(): void
    {
        $group = $this->createGroup('M-101');
        $payload = ['group_id' => $group->id, 'last_name' => 'Ivanov', 'first_name' => 'Dmitry', 'status' => 'active'];

        $this->postJson('/api/students', [...$payload, 'snils' => '112-233-445 95'])->assertCreated();
        $this->postJson('/api/students', [...$payload, 'snils' => '11223344595'])->assertCreated();

        $this->assertSame(1, Person::query()->where('snils_hash', hash('sha256', '11223344595'))->count());
        $this->assertSame(1, Student::query()->distinct('person_id')->count('person_id'));
    }

    public function test_it_updates_student(): void
    {
        $group = $this->createGroup('M-101');
        $student = Student::create([
            'group_id' => $group->id,
            'last_name' => 'Ivanov',
            'first_name' => 'Dmitry',
            'status' => 'active',
        ]);

        $response = $this->patchJson("/api/students/{$student->id}", [
            'status' => 'academic_leave',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.status', 'academic_leave');
    }

    public function test_it_deletes_student(): void
    {
        $group = $this->createGroup('M-101');
        $student = Student::create([
            'group_id' => $group->id,
            'last_name' => 'Ivanov',
            'first_name' => 'Dmitry',
            'status' => 'active',
        ]);

        $this->deleteJson("/api/students/{$student->id}")
            ->assertNoContent();

        // Удаление не окончательное: карточка уходит в корзину, откуда её
        // возвращает или вычищает администратор.
        $this->assertNull(Student::query()->find($student->id));
        $this->assertNotNull(Student::withTrashed()->find($student->id));
    }

    private function createGroup(string $name): Group
    {
        return Group::create([
            'name' => $name,
            'specialty' => 'Instrumental Performance',
            'course' => 1,
            'year_start' => 2026,
        ]);
    }
}
