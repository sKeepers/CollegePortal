<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Person;
use App\Models\Teacher;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ФИО человека показывают три раздела: «Люди», «Сотрудники» и «Преподаватели».
 * «Люди» и «Сотрудники» читают `people`, «Преподаватели» — собственную копию в `teachers`.
 * Тесты закрепляют одно правило: Person — единственный источник записи, копия обязана
 * приходить из него, откуда бы ни пришла правка.
 */
class PersonSharedDataSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->createApiUser(roleCode: 'admin'));
    }

    public function test_employee_card_writes_corrected_name_into_person_and_teacher(): void
    {
        [$person, $employee, $teacher] = $this->personWithBothProfiles();

        $this->patchJson("/api/employees/{$employee->id}", [
            'person_id' => $person->id,
            'last_name' => 'Горбачева',
            'first_name' => 'Татьяна',
            'middle_name' => 'Владимировна',
        ])
            ->assertOk()
            ->assertJsonPath('data.full_name', 'Горбачева Татьяна Владимировна');

        $this->assertDatabaseHas('people', ['id' => $person->id, 'middle_name' => 'Владимировна']);
        $this->assertDatabaseHas('teachers', ['id' => $teacher->id, 'middle_name' => 'Владимировна']);
    }

    public function test_teacher_card_correction_reaches_person_and_employee_card(): void
    {
        [$person, $employee, $teacher] = $this->personWithBothProfiles();

        $this->patchJson("/api/teachers/{$teacher->id}", ['middle_name' => 'Владимировна'])
            ->assertOk()
            ->assertJsonPath('data.middle_name', 'Владимировна');

        $this->assertDatabaseHas('people', ['id' => $person->id, 'middle_name' => 'Владимировна']);

        $this->getJson("/api/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.full_name', 'Горбачева Татьяна Владимировна');
    }

    public function test_person_card_correction_reaches_teacher_profile(): void
    {
        [$person, , $teacher] = $this->personWithBothProfiles();

        $this->patchJson("/api/people/{$person->id}", ['middle_name' => 'Владимировна'])
            ->assertOk()
            ->assertJsonPath('data.middle_name', 'Владимировна');

        $this->assertDatabaseHas('teachers', ['id' => $teacher->id, 'middle_name' => 'Владимировна']);
    }

    public function test_saving_employee_card_again_does_not_revert_corrected_teacher_name(): void
    {
        [, $employee, $teacher] = $this->personWithBothProfiles();

        $this->patchJson("/api/teachers/{$teacher->id}", ['middle_name' => 'Владимировна'])->assertOk();

        // Кадровая карточка переписывает копию в `teachers` из Person при каждом сохранении.
        // Пока правка преподавателя не доходила до Person, следующее же сохранение
        // возвращало опечатку обратно.
        $this->patchJson("/api/employees/{$employee->id}", ['workload_rate' => 0.5])->assertOk();

        $this->assertDatabaseHas('teachers', ['id' => $teacher->id, 'middle_name' => 'Владимировна']);
    }

    public function test_employee_card_does_not_clear_person_data_it_does_not_show(): void
    {
        [$person, $employee] = $this->personWithBothProfiles();
        $person->forceFill(['snils' => '11223344595', 'phone' => '9990001122'])->save();

        $this->patchJson("/api/employees/{$employee->id}", [
            'person_id' => $person->id,
            'last_name' => 'Горбачева',
            'first_name' => 'Татьяна',
            'middle_name' => 'Владимировна',
            'snils' => '',
            'phone' => '',
        ])->assertOk();

        $this->assertDatabaseHas('people', [
            'id' => $person->id,
            'middle_name' => 'Владимировна',
            'snils' => '11223344595',
            'phone' => '9990001122',
        ]);
    }

    public function test_person_card_can_clear_shared_field_and_clearing_reaches_teacher(): void
    {
        [$person, , $teacher] = $this->personWithBothProfiles();
        $person->forceFill(['phone' => '9990001122'])->save();
        $teacher->forceFill(['phone' => '9990001122'])->save();

        $this->patchJson("/api/people/{$person->id}", ['phone' => null])->assertOk();

        $this->assertNull($person->fresh()->phone);
        $this->assertNull($teacher->fresh()->phone);
    }

    public function test_switching_employee_to_another_person_leaves_the_previous_one_untouched(): void
    {
        [$person, $employee] = $this->personWithBothProfiles();
        $other = Person::create(['last_name' => 'Смирнова', 'first_name' => 'Ольга', 'status' => 'active']);

        $this->patchJson("/api/employees/{$employee->id}", [
            'person_id' => $other->id,
            'last_name' => 'Смирнова',
            'first_name' => 'Ольга',
        ])->assertOk();

        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'person_id' => $other->id]);
        $this->assertDatabaseHas('people', ['id' => $person->id, 'last_name' => 'Горбачева', 'first_name' => 'Татьяна']);
    }

    /** @return array{0: Person, 1: Employee, 2: Teacher} */
    private function personWithBothProfiles(): array
    {
        $person = Person::create([
            'last_name' => 'Горбачева',
            'first_name' => 'Татьяна',
            'middle_name' => 'Вледимировна',
            'status' => 'active',
        ]);

        $employee = Employee::create([
            'person_id' => $person->id,
            'employee_number' => 'EMP-SYNC-1',
            'status' => 'active',
            'employment_type' => 'full_time',
            'is_teacher' => true,
        ]);

        $teacher = Teacher::create([
            'person_id' => $person->id,
            'last_name' => 'Горбачева',
            'first_name' => 'Татьяна',
            'middle_name' => 'Вледимировна',
            'is_active' => true,
        ]);

        return [$person, $employee, $teacher];
    }
}
