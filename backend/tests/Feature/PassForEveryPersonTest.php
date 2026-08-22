<?php

namespace Tests\Feature;

use App\Models\DigitalIdentity;
use App\Models\Employee;
use App\Models\Group;
use App\Models\Person;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Пропуск есть у каждого человека.
 *
 * Решение владельца 21.08.2026. Раньше пропуск появлялся побочно — при
 * заведении учётной записи или отдельным действием кадров, — и кого завели
 * иначе, тот узнавал об отсутствии пропуска у турникета.
 *
 * Пропуск принадлежит человеку, а не карточке: у преподавателя, который ещё и
 * сотрудник, человек один, и пропуск ему нужен один.
 */
class PassForEveryPersonTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_student_gets_a_pass_at_once(): void
    {
        $person = $this->createPerson('Студентов');
        $group = Group::create(['name' => 'М-201', 'specialty' => 'Проверка', 'course' => 1, 'year_start' => 2026]);

        $student = Student::create([
            'group_id' => $group->id,
            'person_id' => $person->id,
            'last_name' => 'Студентов',
            'first_name' => 'Проверочный',
            'status' => 'active',
        ]);

        $pass = DigitalIdentity::query()->where('person_id', $person->id)->first();

        $this->assertNotNull($pass, 'Студент остался без пропуска');
        $this->assertSame(DigitalIdentity::STATUS_ACTIVE, $pass->status);
        $this->assertSame(DigitalIdentity::ENTITY_STUDENT, $pass->entity_type);
        $this->assertSame($student->id, $pass->entity_id);
    }

    public function test_a_new_employee_and_a_new_teacher_get_a_pass_too(): void
    {
        $employeePerson = $this->createPerson('Сотрудников');
        Employee::create([
            'person_id' => $employeePerson->id,
            'employee_number' => 'EMP-'.$employeePerson->id,
            'status' => 'active',
            'employment_type' => 'main',
            'is_teacher' => false,
        ]);

        $teacherPerson = $this->createPerson('Преподавателев');
        Teacher::create([
            'person_id' => $teacherPerson->id,
            'last_name' => 'Преподавателев',
            'first_name' => 'Проверочный',
        ]);

        $this->assertSame(DigitalIdentity::ENTITY_EMPLOYEE,
            DigitalIdentity::query()->where('person_id', $employeePerson->id)->value('entity_type'));
        $this->assertSame(DigitalIdentity::ENTITY_TEACHER,
            DigitalIdentity::query()->where('person_id', $teacherPerson->id)->value('entity_type'));
    }

    public function test_a_person_with_two_cards_gets_one_pass_not_two(): void
    {
        $person = $this->createPerson('Двойнов');

        Teacher::create(['person_id' => $person->id, 'last_name' => 'Двойнов', 'first_name' => 'Проверочный']);
        Employee::create([
            'person_id' => $person->id,
            'employee_number' => 'EMP-'.$person->id,
            'status' => 'active',
            'employment_type' => 'main',
            'is_teacher' => true,
        ]);

        // Пропуск принадлежит человеку: вторая карточка второго пропуска не даёт.
        $this->assertSame(1, DigitalIdentity::query()->where('person_id', $person->id)->count());
    }

    public function test_a_pass_appears_when_the_person_is_linked_later(): void
    {
        $group = Group::create(['name' => 'М-203', 'specialty' => 'Проверка', 'course' => 1, 'year_start' => 2026]);

        // Так делает загрузка контингента: сначала пишет строку студента, потом
        // ищет или заводит человека и привязывает его. На заведении пропуск
        // выдавать было не к кому — первые десять зачисленных 22.08.2026 так и
        // остались без пропусков.
        $student = Student::create([
            'group_id' => $group->id,
            'last_name' => 'Позднев',
            'first_name' => 'Проверочный',
            'status' => 'active',
        ]);

        $this->assertSame(0, DigitalIdentity::query()->count(), 'Пропуск выдан карточке без человека');

        $person = $this->createPerson('Позднев');
        $student->forceFill(['person_id' => $person->id])->save();

        $pass = DigitalIdentity::query()->where('person_id', $person->id)->first();

        $this->assertNotNull($pass, 'Пропуск не выдан после привязки человека');
        $this->assertSame(DigitalIdentity::STATUS_ACTIVE, $pass->status);
        $this->assertSame(DigitalIdentity::ENTITY_STUDENT, $pass->entity_type);
    }

    public function test_the_command_gives_a_pass_to_those_left_without_one(): void
    {
        $person = $this->createPerson('Забытов');
        Employee::create([
            'person_id' => $person->id,
            'employee_number' => 'EMP-'.$person->id,
            'status' => 'active',
            'employment_type' => 'main',
            'is_teacher' => false,
        ]);

        // Возвращаем состояние, в котором человек остался без пропуска.
        DigitalIdentity::query()->where('person_id', $person->id)->delete();
        $this->assertSame(0, DigitalIdentity::query()->where('person_id', $person->id)->count());

        $this->artisan('identity:issue-missing')->assertSuccessful();
        $this->assertSame(0, DigitalIdentity::query()->where('person_id', $person->id)->count(),
            'Без --apply команда не должна ничего выдавать');

        $this->artisan('identity:issue-missing', ['--apply' => true])->assertSuccessful();
        $this->assertSame(1, DigitalIdentity::query()->where('person_id', $person->id)->count());
    }

    public function test_a_card_without_a_person_does_not_break_anything(): void
    {
        $group = Group::create(['name' => 'М-202', 'specialty' => 'Проверка', 'course' => 1, 'year_start' => 2026]);

        // Карточка без человека — наследство прежних загрузок. Пропуск привязать
        // не к кому, но заведение карточки от этого падать не должно.
        $student = Student::create([
            'group_id' => $group->id,
            'last_name' => 'Безличный',
            'first_name' => 'Проверочный',
            'status' => 'active',
        ]);

        $this->assertNotNull($student->id);
        $this->assertSame(0, DigitalIdentity::query()->where('entity_id', $student->id)
            ->where('entity_type', DigitalIdentity::ENTITY_STUDENT)->count());
    }

    private function createPerson(string $lastName): Person
    {
        return Person::create([
            'last_name' => $lastName,
            'first_name' => 'Проверочный',
            'status' => 'active',
        ]);
    }
}
