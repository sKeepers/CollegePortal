<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Person;
use App\Models\RfidCard;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Человек видит свою карту, не получая доступа к реестру.
 *
 * Решение владельца 01.09.2026: сотруднику нужен только QR-пропуск и карта.
 * Реестр карт закрыт правом `rfid.cards.view`, и открывать его ради номера
 * нельзя — это дало бы и чужие карты. Отсюда отдельная ручка за правом
 * «видеть своё».
 *
 * Замер 01.09.2026 до правки: экрана, где человек видит свою карту, в портале
 * не было вовсе — страница «Мой QR-пропуск» карту не показывала, а `RfidCardsPage`
 * это реестр всех.
 */
class MyCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_person_sees_his_own_card(): void
    {
        $person = Person::create(['last_name' => 'Свой', 'first_name' => 'Семён', 'status' => 'active']);
        $this->card('0000000101', $person);

        $this->withApiAuth($this->employee($person));

        $this->getJson('/api/rfid-cards/mine')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uid', '0000000101');
    }

    public function test_he_does_not_see_anyone_elses(): void
    {
        // Без этой проверки первая проходила бы и у ручки, отдающей весь реестр.
        $mine = Person::create(['last_name' => 'Свой', 'first_name' => 'Семён', 'status' => 'active']);
        $other = Person::create(['last_name' => 'Чужой', 'first_name' => 'Чеслав', 'status' => 'active']);
        $this->card('0000000101', $mine);
        $this->card('0000000202', $other);

        $this->withApiAuth($this->employee($mine));

        $response = $this->getJson('/api/rfid-cards/mine')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('0000000101', $response->json('data.0.uid'));
    }

    public function test_a_person_without_a_card_gets_an_empty_list_not_an_error(): void
    {
        // У 79 сотрудников карты есть не у всех, и первый же без карты откроет
        // эту страницу. Пустой список — ответ, отказ — поломка.
        $person = Person::create(['last_name' => 'Безкарты', 'first_name' => 'Борис', 'status' => 'active']);

        $this->withApiAuth($this->employee($person));

        $this->getJson('/api/rfid-cards/mine')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_account_without_a_person_card_gets_an_empty_list_too(): void
    {
        // Правило портала: отсутствие связанного профиля не даёт 403. Учётная
        // запись без карточки человека обязана получить пустой список.
        $user = User::factory()->create(['is_active' => true, 'person_id' => null]);
        $user->roles()->attach($this->roleWithOwnData()->id);

        $this->withApiAuth($user);

        $this->getJson('/api/rfid-cards/mine')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_registry_stays_closed_to_him(): void
    {
        // Главное разграничение: своя карта — да, реестр — нет.
        $person = Person::create(['last_name' => 'Свой', 'first_name' => 'Семён', 'status' => 'active']);
        $this->card('0000000101', $person);

        $this->withApiAuth($this->employee($person));

        $this->getJson('/api/rfid-cards')->assertForbidden();
        $this->getJson('/api/rfid-cards/journal')->assertForbidden();
    }

    public function test_several_cards_are_all_shown(): void
    {
        // С 30.08.2026 у человека законно бывает несколько карт. Показать одну
        // значило бы умолчать об остальных.
        $person = Person::create(['last_name' => 'Двукарточный', 'first_name' => 'Дмитрий', 'status' => 'active']);
        $this->card('0000000101', $person);
        $this->card('0000000202', $person);

        $this->withApiAuth($this->employee($person));

        $this->getJson('/api/rfid-cards/mine')->assertOk()->assertJsonCount(2, 'data');
    }

    private function card(string $uid, Person $person): RfidCard
    {
        return RfidCard::create([
            'uid' => $uid,
            'person_id' => $person->id,
            'status' => RfidCard::STATUS_ISSUED,
            'issued_at' => now(),
        ]);
    }

    private function employee(Person $person): User
    {
        $user = User::factory()->create(['is_active' => true, 'person_id' => $person->id]);
        $user->roles()->attach($this->roleWithOwnData()->id);

        return $user;
    }

    private function roleWithOwnData(): Role
    {
        $role = Role::query()->firstOrCreate(
            ['code' => 'employee_own_data'],
            ['name' => 'Сотрудник (проба)', 'description' => 'Только своё'],
        );

        $permission = Permission::query()->firstOrCreate(
            ['code' => 'view_own_data'],
            ['name' => 'view_own_data', 'module' => 'Test', 'description' => 'своё', 'system' => true, 'active' => true],
        );

        $role->permissions()->syncWithoutDetaching([$permission->id]);

        return $role;
    }
}
