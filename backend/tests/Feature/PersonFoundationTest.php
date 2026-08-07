<?php

namespace Tests\Feature;

use App\Models\ApplicantApplication;
use App\Models\EducationProgram;
use App\Models\Employee;
use App\Models\Group;
use App\Models\Person;
use App\Models\Specialty;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\PersonService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_person_can_have_multiple_profiles(): void
    {
        $group = $this->group();
        $person = Person::create(['last_name' => 'Иванов', 'first_name' => 'Иван', 'status' => 'active']);
        $student = Student::create(['person_id' => $person->id, 'group_id' => $group->id, 'last_name' => 'Иванов', 'first_name' => 'Иван', 'status' => 'active']);
        $teacher = Teacher::create(['person_id' => $person->id, 'last_name' => 'Иванов', 'first_name' => 'Иван', 'is_active' => true]);

        $this->assertTrue($person->students()->whereKey($student)->exists());
        $this->assertTrue($person->teachers()->whereKey($teacher)->exists());
    }

    public function test_people_api_lists_every_person_and_filters_students_only_on_request(): void
    {
        $this->seed(RoleSeeder::class);
        $director = $this->createApiUser(roleCode: 'director');
        $teacher = $this->createApiUser(roleCode: 'teacher');
        $group = $this->group();

        $studentPerson = Person::create(['last_name' => 'Петрова', 'first_name' => 'Анна', 'status' => 'active']);
        Student::create(['person_id' => $studentPerson->id, 'group_id' => $group->id, 'last_name' => 'Петрова', 'first_name' => 'Анна', 'status' => 'active']);

        $employeePerson = Person::create(['last_name' => 'Иванова', 'first_name' => 'Мария', 'status' => 'active']);
        Employee::create(['person_id' => $employeePerson->id, 'employee_number' => 'EMP-PEOPLE-1', 'status' => 'active', 'employment_type' => 'full_time']);

        $names = fn (array $response): array => collect($response['data'])->pluck('full_name')->all();

        $all = $this->withApiAuth($director)->getJson('/api/people')->assertOk()->json();
        $this->assertEqualsCanonicalizing(['Иванова Мария', 'Петрова Анна'], $names($all));

        $withoutStudents = $this->withApiAuth($director)->getJson('/api/people?profile=without_students')->assertOk()->json();
        $this->assertSame(['Иванова Мария'], $names($withoutStudents));

        $studentsOnly = $this->withApiAuth($director)->getJson('/api/people?profile=student')->assertOk()->json();
        $this->assertSame(['Петрова Анна'], $names($studentsOnly));

        $this->withApiAuth($director)
            ->getJson("/api/people/{$employeePerson->id}")
            ->assertOk()
            ->assertJsonPath('data.full_name', 'Иванова Мария')
            ->assertJsonCount(1, 'data.employees');

        $this->withApiAuth($teacher)->getJson('/api/people')->assertForbidden();
    }

    public function test_person_with_both_student_and_employee_profiles_stays_in_the_registry(): void
    {
        $this->seed(RoleSeeder::class);
        $director = $this->createApiUser(roleCode: 'director');
        $group = $this->group();

        // A student working as a lab assistant: excluding anyone who has a student profile
        // used to erase this person from the registry entirely, including for HR.
        $person = Person::create(['last_name' => 'Сидоров', 'first_name' => 'Павел', 'status' => 'active']);
        Student::create(['person_id' => $person->id, 'group_id' => $group->id, 'last_name' => 'Сидоров', 'first_name' => 'Павел', 'status' => 'active']);
        Employee::create(['person_id' => $person->id, 'employee_number' => 'EMP-PEOPLE-2', 'status' => 'active', 'employment_type' => 'part_time']);

        $this->withApiAuth($director)
            ->getJson('/api/people?profile=employee')
            ->assertOk()
            ->assertJsonPath('data.0.full_name', 'Сидоров Павел')
            ->assertJsonPath('data.0.profiles_count.students', 1)
            ->assertJsonPath('data.0.profiles_count.employees', 1);

        $this->withApiAuth($director)
            ->getJson('/api/people?profile=without_students')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_duplicate_search_uses_snils_and_ambiguous_duplicates_are_not_auto_linked(): void
    {
        $service = app(PersonService::class);
        Person::create(['last_name' => 'Сидоров', 'first_name' => 'Петр', 'snils' => '12345678901', 'status' => 'active']);
        $this->assertSame(1, $service->findPossibleDuplicates(['last_name' => 'Другой', 'first_name' => 'Человек', 'snils' => '123-456-789 01'])->count());

        Person::create(['last_name' => 'Сидоров', 'first_name' => 'Петр', 'phone' => '79990000000', 'status' => 'active']);
        Person::create(['last_name' => 'Сидоров', 'first_name' => 'Петр', 'phone' => '79990000000', 'status' => 'active']);
        $group = $this->group();
        $student = Student::create(['group_id' => $group->id, 'last_name' => 'Сидоров', 'first_name' => 'Петр', 'phone' => '+7 999 000-00-00', 'status' => 'active']);

        $this->artisan('person:link-existing --apply')->assertSuccessful();

        $student->refresh();
        $this->assertNull($student->person_id);
        $this->assertSame(3, Person::count());
    }

    public function test_dry_run_does_not_change_database_and_apply_links_existing_profiles(): void
    {
        $group = $this->group();
        Student::create(['group_id' => $group->id, 'last_name' => 'Андреева', 'first_name' => 'Мария', 'email' => 'maria@example.test', 'status' => 'active']);
        Teacher::create(['last_name' => 'Андреева', 'first_name' => 'Мария', 'email' => 'maria@example.test', 'is_active' => true]);

        $this->artisan('person:link-existing --dry-run')
            ->expectsOutputToContain('dry-run')
            ->assertSuccessful();

        $this->assertSame(0, Person::count());
        $this->assertSame(0, Student::whereNotNull('person_id')->count());

        $this->artisan('person:link-existing --apply')->assertSuccessful();

        $this->assertSame(1, Person::count());
        $this->assertSame(1, Student::whereNotNull('person_id')->count());
        $this->assertSame(1, Teacher::whereNotNull('person_id')->count());
    }

    public function test_existing_student_teacher_admission_and_graduate_api_keep_working(): void
    {
        $this->withApiAuth();
        $group = $this->group();
        $specialty = Specialty::create(['code' => '53.02.03', 'name' => 'Инструментальное исполнительство']);
        $program = EducationProgram::create(['specialty_id' => $specialty->id, 'name' => 'Фортепиано', 'year_start' => 2026]);
        $student = Student::create(['group_id' => $group->id, 'last_name' => 'Кузнецов', 'first_name' => 'Илья', 'status' => 'active']);
        Teacher::create(['last_name' => 'Смирнова', 'first_name' => 'Елена', 'is_active' => true]);
        ApplicantApplication::create(['education_program_id' => $program->id, 'last_name' => 'Орлова', 'first_name' => 'Вера', 'status' => 'new', 'submitted_at' => '2026-07-01']);

        $this->getJson('/api/students')->assertOk()->assertJsonPath('data.0.last_name', 'Кузнецов');
        $this->getJson('/api/teachers')->assertOk()->assertJsonPath('data.0.last_name', 'Смирнова');
        $this->getJson('/api/applicant-applications')->assertOk()->assertJsonPath('data.0.last_name', 'Орлова');
        $this->postJson('/api/graduates', [
            'student_id' => $student->id,
            'group_id' => $group->id,
            'education_program_id' => $program->id,
            'specialty_id' => $specialty->id,
            'graduation_year' => 2027,
            'status' => 'draft',
        ])->assertCreated();
        $this->getJson('/api/graduates')->assertOk()->assertJsonPath('data.0.student_id', $student->id);
    }

    private function group(): Group
    {
        return Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]);
    }

}
