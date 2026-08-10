<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Person;
use App\Models\Student;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Замечание владельца: карточка приняла изменённые ИНН и СНИЛС без единой проверки.
 *
 * Опечатка в этих номерах не видна глазами и всплывает поздно — в выгрузке для ФИС или
 * в документе, где номер уже напечатан. Поэтому проверка стоит на входе.
 *
 * Второе правило, которое здесь закрепляется: **проверка не превращает «не менять» и
 * «очистить» в ошибку.** Пустое поле в профильной карточке значит «не менять», в карточке
 * человека — «очистить»; ни одно из двух не является неверным вводом.
 */
class PersonIdentifierValidationTest extends TestCase
{
    use RefreshDatabase;

    /** Контрольное число сходится. Тот же номер используют тесты приёмной комиссии. */
    private const VALID_SNILS = '112-233-445 95';

    /** Тот же номер с испорченным последним разрядом. */
    private const INVALID_SNILS = '112-233-445 96';

    private const VALID_INN_PERSON = '500100123426';

    private const VALID_INN_ORGANIZATION = '7707083893';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->createApiUser(roleCode: 'admin'));
    }

    public function test_person_card_rejects_snils_with_a_broken_checksum(): void
    {
        $person = $this->person();

        $this->patchJson("/api/people/{$person->id}", ['snils' => self::INVALID_SNILS])
            ->assertStatus(422)
            ->assertJsonValidationErrors('snils');

        $this->assertDatabaseHas('people', ['id' => $person->id, 'snils' => null]);
    }

    public function test_person_card_rejects_snils_of_the_wrong_length(): void
    {
        $person = $this->person();

        $this->patchJson("/api/people/{$person->id}", ['snils' => '112-233-445'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('snils');
    }

    public function test_person_card_accepts_a_correct_snils(): void
    {
        $person = $this->person();

        $this->patchJson("/api/people/{$person->id}", ['snils' => self::VALID_SNILS])->assertOk();

        // Хранится в цифрах: разделители — оформление, и по ним человека не найти.
        $this->assertDatabaseHas('people', ['id' => $person->id, 'snils' => '11223344595']);
    }

    public function test_person_card_rejects_inn_with_a_broken_control_digit(): void
    {
        $person = $this->person();

        $this->patchJson("/api/people/{$person->id}", ['inn' => '500100123427'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('inn');
    }

    public function test_person_card_rejects_inn_of_a_length_that_does_not_exist(): void
    {
        $person = $this->person();

        $this->patchJson("/api/people/{$person->id}", ['inn' => '12345678901'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('inn');
    }

    public function test_person_card_accepts_both_lengths_of_a_correct_inn(): void
    {
        $person = $this->person();

        $this->patchJson("/api/people/{$person->id}", ['inn' => self::VALID_INN_PERSON])->assertOk();
        $this->assertDatabaseHas('people', ['id' => $person->id, 'inn' => self::VALID_INN_PERSON]);

        $this->patchJson("/api/people/{$person->id}", ['inn' => self::VALID_INN_ORGANIZATION])->assertOk();
        $this->assertDatabaseHas('people', ['id' => $person->id, 'inn' => self::VALID_INN_ORGANIZATION]);
    }

    /**
     * Карточка человека — единственное место, где общее поле можно очистить. Проверка не
     * вправе это запретить, иначе неверный номер станет невозможно убрать.
     */
    public function test_person_card_still_clears_both_fields_with_an_empty_value(): void
    {
        $person = $this->person();
        $person->forceFill(['snils' => '11223344595', 'inn' => self::VALID_INN_PERSON])->save();

        $this->patchJson("/api/people/{$person->id}", ['snils' => null, 'inn' => null])->assertOk();

        $this->assertDatabaseHas('people', ['id' => $person->id, 'snils' => null, 'inn' => null]);
    }

    public function test_student_card_rejects_snils_with_a_broken_checksum(): void
    {
        [, $student] = $this->personWithStudentProfile();

        $this->patchJson("/api/students/{$student->id}", ['snils' => self::INVALID_SNILS])
            ->assertStatus(422)
            ->assertJsonValidationErrors('snils');
    }

    /**
     * Профильная карточка видит человека не целиком, и пустое поле в ней значит «не менять».
     * Проверка обязана пропустить такое сохранение — иначе учебную карточку нельзя будет
     * сохранить, пока оператор не введёт СНИЛС, которого он не видит.
     */
    public function test_student_card_saves_with_an_empty_snils(): void
    {
        [, $student] = $this->personWithStudentProfile();

        $this->patchJson("/api/students/{$student->id}", ['snils' => null, 'phone' => '+79990000001'])->assertOk();
    }

    private function person(): Person
    {
        return Person::create([
            'last_name' => 'Горбачева',
            'first_name' => 'Татьяна',
            'middle_name' => 'Владимировна',
            'status' => 'active',
        ]);
    }

    /** @return array{0: Person, 1: Student} */
    private function personWithStudentProfile(): array
    {
        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]);
        $person = $this->person();

        $student = Student::create([
            'person_id' => $person->id,
            'group_id' => $group->id,
            'last_name' => 'Горбачева',
            'first_name' => 'Татьяна',
            'middle_name' => 'Владимировна',
            'status' => 'active',
        ]);

        return [$person, $student];
    }
}
