<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Person;
use App\Models\RfidCard;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Учёт RFID-карт: кабинет коменданта.
 *
 * Карты выдают под роспись, теряют, блокируют и принимают обратно. Портал
 * заменяет тетрадь, поэтому выдача и приём — отдельные действия, а не правка
 * поля: иначе не остаётся следа, кому и когда карта ушла.
 */
class RfidCardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_commandant_role_arrives_with_its_permissions(): void
    {
        $this->seed(RoleSeeder::class);

        $role = Role::query()->where('code', 'commandant')->first();

        $this->assertNotNull($role, 'Роль коменданта не заведена');
        foreach (['rfid.cards.view', 'rfid.cards.manage', 'view_own_data', 'people.view'] as $code) {
            $this->assertTrue($role->permissions()->where('code', $code)->exists(), "Коменданту не выдано {$code}");
        }
    }

    public function test_a_card_is_registered_and_issued_to_a_person(): void
    {
        $this->withApiAuth($this->commandant());
        $person = $this->createPerson('Проверкин');

        $card = $this->postJson('/api/rfid-cards', ['uid' => 'CARD-001', 'label' => '№ 12'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'stock')
            ->json('data');

        $this->postJson("/api/rfid-cards/{$card['id']}/issue", ['person_id' => $person->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'issued')
            ->assertJsonPath('data.person_id', $person->id);

        $this->assertNotNull(RfidCard::query()->find($card['id'])->issued_at);
    }

    public function test_a_card_on_someones_hands_is_not_issued_twice(): void
    {
        $this->withApiAuth($this->commandant());
        $first = $this->createPerson('Первый');
        $second = $this->createPerson('Второй');
        $card = $this->createCard('CARD-002');

        $this->postJson("/api/rfid-cards/{$card->id}/issue", ['person_id' => $first->id])->assertOk();

        $this->postJson("/api/rfid-cards/{$card->id}/issue", ['person_id' => $second->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.card.0', 'Карта уже выдана. Сначала примите её обратно.');

        $this->assertSame($first->id, RfidCard::query()->find($card->id)->person_id);
    }

    public function test_a_person_does_not_hold_two_cards_at_once(): void
    {
        $this->withApiAuth($this->commandant());
        $person = $this->createPerson('Двойнов');
        $first = $this->createCard('CARD-003');
        $second = $this->createCard('CARD-004');

        $this->postJson("/api/rfid-cards/{$first->id}/issue", ['person_id' => $person->id])->assertOk();

        // Вторая карта означала бы, что первую потеряли и не отметили: на
        // проходной прошли бы обе.
        $this->postJson("/api/rfid-cards/{$second->id}/issue", ['person_id' => $person->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.person_id.0', 'У человека уже есть карта на руках — CARD-003. Сначала примите её.');
    }

    public function test_a_card_is_accepted_back_and_can_be_issued_again(): void
    {
        $this->withApiAuth($this->commandant());
        $person = $this->createPerson('Возвратов');
        $card = $this->createCard('CARD-005');

        $this->postJson("/api/rfid-cards/{$card->id}/issue", ['person_id' => $person->id])->assertOk();
        $this->postJson("/api/rfid-cards/{$card->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'stock');

        // Человек в карте остаётся: «принята у кого» — это и есть история.
        $accepted = RfidCard::query()->find($card->id);
        $this->assertSame($person->id, $accepted->person_id);
        $this->assertNotNull($accepted->returned_at);

        $this->postJson("/api/rfid-cards/{$card->id}/issue", ['person_id' => $person->id])->assertOk();
    }

    public function test_a_lost_card_is_not_issued_until_it_returns_to_stock(): void
    {
        $this->withApiAuth($this->commandant());
        $person = $this->createPerson('Потеряев');
        $card = $this->createCard('CARD-006');

        $this->postJson("/api/rfid-cards/{$card->id}/status", ['status' => 'lost'])->assertOk();

        $this->postJson("/api/rfid-cards/{$card->id}/issue", ['person_id' => $person->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.card.0', 'Карта числится утерянной или списанной. Верните её в оборот, прежде чем выдавать.');

        $this->postJson("/api/rfid-cards/{$card->id}/status", ['status' => 'stock'])->assertOk();
        $this->postJson("/api/rfid-cards/{$card->id}/issue", ['person_id' => $person->id])->assertOk();
    }

    public function test_issuing_through_the_status_route_is_refused(): void
    {
        $this->withApiAuth($this->commandant());
        $card = $this->createCard('CARD-007');

        // Иначе карта оказалась бы «на руках» неизвестно у кого.
        $this->postJson("/api/rfid-cards/{$card->id}/status", ['status' => 'issued'])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'Выдать карту можно только выдачей — тогда портал запишет, кому и когда.');
    }

    public function test_without_the_permission_the_registry_is_closed(): void
    {
        $this->withApiAuth($this->userWith(['dashboard.view']));

        $this->getJson('/api/rfid-cards')->assertForbidden();
        $this->postJson('/api/rfid-cards', ['uid' => 'CARD-008'])->assertForbidden();
    }

    public function test_a_viewer_sees_the_registry_but_does_not_change_it(): void
    {
        $this->createCard('CARD-009');
        $this->withApiAuth($this->userWith(['rfid.cards.view']));

        $this->getJson('/api/rfid-cards')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/rfid-cards', ['uid' => 'CARD-010'])->assertForbidden();
    }

    private function createCard(string $uid): RfidCard
    {
        return RfidCard::create(['uid' => $uid, 'status' => RfidCard::STATUS_STOCK]);
    }

    private function createPerson(string $lastName): Person
    {
        return Person::create(['last_name' => $lastName, 'first_name' => 'Проверочный', 'status' => 'active']);
    }

    private function commandant(): User
    {
        return $this->userWith(['rfid.cards.view', 'rfid.cards.manage', 'people.view']);
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(
            ['code' => 'rfid_'.substr(md5(json_encode($permissions)), 0, 12)],
            ['name' => 'Карты '.substr(md5(json_encode($permissions)), 0, 8), 'description' => 'Test role'],
        );

        foreach ($permissions as $code) {
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                ['name' => $code, 'module' => 'Test', 'description' => $code, 'system' => true, 'active' => true],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->roles()->attach($role->id);

        return $user;
    }
}
