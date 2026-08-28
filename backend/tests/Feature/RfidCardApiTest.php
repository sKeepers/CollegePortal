<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Permission;
use App\Models\Person;
use App\Models\RfidCard;
use App\Models\RfidCardIssue;
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

        $card = $this->postJson('/api/rfid-cards', ['uid' => '0000000001', 'label' => '№ 12'])
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
        $card = $this->createCard('0000000002');

        $this->postJson("/api/rfid-cards/{$card->id}/issue", ['person_id' => $first->id])->assertOk();

        $this->postJson("/api/rfid-cards/{$card->id}/issue", ['person_id' => $second->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.card.0', 'Карта уже выдана. Сначала примите её обратно.');

        $this->assertSame($first->id, RfidCard::query()->find($card->id)->person_id);
    }

    public function test_a_person_may_hold_two_cards_at_once(): void
    {
        $this->withApiAuth($this->commandant());
        $person = $this->createPerson('Двойнов');
        $first = $this->createCard('0000000003');
        $second = $this->createCard('0000000004');

        $this->postJson("/api/rfid-cards/{$first->id}/issue", ['person_id' => $person->id])->assertOk();

        // Проверка перевёрнута 28.08.2026. Раньше здесь стоял отказ со словами
        // «вторая карта означала бы, что первую потеряли и не отметили: на
        // проходной прошли бы обе». Владелец сказал прямо, что на человека
        // бывает записано несколько карт, — и «прошли бы обе» перестало быть
        // доводом против: это ровно то, что нужно. Подробности и поведение
        // турникета — в `SeveralCardsPerPersonTest`.
        $this->postJson("/api/rfid-cards/{$second->id}/issue", ['person_id' => $person->id])->assertOk();

        $this->assertSame(2, RfidCard::query()
            ->where('person_id', $person->id)
            ->where('status', RfidCard::STATUS_ISSUED)
            ->count());
    }

    public function test_a_card_is_accepted_back_and_can_be_issued_again(): void
    {
        $this->withApiAuth($this->commandant());
        $person = $this->createPerson('Возвратов');
        $card = $this->createCard('0000000005');

        $this->postJson("/api/rfid-cards/{$card->id}/issue", ['person_id' => $person->id])->assertOk();
        $this->postJson("/api/rfid-cards/{$card->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'stock');

        // Принятая карта ни за кем не числится: `person_id` — это «у кого она
        // сейчас». Пока прежний владелец в ней оставался, сданная карта в
        // реестре выглядела всё ещё выданной. Кто её держал — в журнале.
        $accepted = RfidCard::query()->find($card->id);
        $this->assertNull($accepted->person_id);
        $this->assertNotNull($accepted->returned_at);
        $this->assertSame($person->id, RfidCardIssue::query()->where('rfid_card_id', $card->id)->value('person_id'));

        $this->postJson("/api/rfid-cards/{$card->id}/issue", ['person_id' => $person->id])->assertOk();
    }

    public function test_a_lost_card_is_not_issued_until_it_returns_to_stock(): void
    {
        $this->withApiAuth($this->commandant());
        $person = $this->createPerson('Потеряев');
        $card = $this->createCard('0000000006');

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
        $card = $this->createCard('0000000007');

        // Иначе карта оказалась бы «на руках» неизвестно у кого.
        $this->postJson("/api/rfid-cards/{$card->id}/status", ['status' => 'issued'])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'Выдать карту можно только выдачей — тогда портал запишет, кому и когда.');
    }

    public function test_without_the_permission_the_registry_is_closed(): void
    {
        $this->withApiAuth($this->userWith(['dashboard.view']));

        $this->getJson('/api/rfid-cards')->assertForbidden();
        $this->postJson('/api/rfid-cards', ['uid' => '0000000008'])->assertForbidden();
    }

    public function test_a_viewer_sees_the_registry_but_does_not_change_it(): void
    {
        $this->createCard('0000000009');
        $this->withApiAuth($this->userWith(['rfid.cards.view']));

        $this->getJson('/api/rfid-cards')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/rfid-cards', ['uid' => '0000000010'])->assertForbidden();
    }

    public function test_a_card_is_bound_by_the_reader_without_registering_it_first(): void
    {
        $this->withApiAuth($this->commandant());
        $person = $this->createPerson('Пришедший');

        // Главный путь: комендант нашёл человека и поднёс карту. Номер портал
        // видит впервые — карта заводится сама, отдельного шага нет.
        $this->postJson('/api/rfid-cards/bind', ['person_id' => $person->id, 'uid' => '0000000101'])
            ->assertOk()
            ->assertJsonPath('data.status', 'issued')
            ->assertJsonPath('data.person_id', $person->id);

        $card = RfidCard::query()->firstWhere('uid', '0000000101');
        $this->assertNotNull($card, 'Незнакомая карта не завелась при привязке');
        $this->assertSame(1, RfidCardIssue::query()->where('rfid_card_id', $card->id)->whereNull('returned_at')->count());
    }

    public function test_the_reader_finds_the_person_by_the_card_number(): void
    {
        $this->withApiAuth($this->commandant());
        $person = $this->createPerson('Сдающий');
        $this->postJson('/api/rfid-cards/bind', ['person_id' => $person->id, 'uid' => '0000000201'])->assertOk();

        // Сценарий «пришёл сдать»: поднесли карту — открылся человек.
        $this->getJson('/api/rfid-cards/lookup?uid=0000000201')
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('person.full_name', 'Сдающий Проверочный');

        // Тот же номер без ведущих нулей — та же карта. Считыватели дополняют
        // номер нулями по-разному, и на этом легко потерять карту.
        $this->getJson('/api/rfid-cards/lookup?uid=201')
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('card.uid', '0000000201');

        $this->getJson('/api/rfid-cards/lookup?uid=0000000999')
            ->assertOk()
            ->assertJsonPath('found', false);
    }

    public function test_a_lost_card_frees_the_person_for_a_new_one(): void
    {
        $this->withApiAuth($this->commandant());
        $person = $this->createPerson('Потерявший');

        $this->postJson('/api/rfid-cards/bind', ['person_id' => $person->id, 'uid' => '0000000301'])->assertOk();
        $lost = RfidCard::query()->firstWhere('uid', '0000000301');

        $this->postJson("/api/rfid-cards/{$lost->id}/status", ['status' => 'lost'])->assertOk();
        $this->postJson('/api/rfid-cards/bind', ['person_id' => $person->id, 'uid' => '0000000302'])->assertOk();

        $rows = collect($this->getJson('/api/rfid-cards/journal?person_id='.$person->id)->assertOk()->json('data'));

        $this->assertCount(2, $rows, 'В журнале должно остаться обе выдачи: утерянная и новая');
        $this->assertSame(1, $rows->where('is_open', true)->count());
        $this->assertSame('lost', $rows->firstWhere('is_open', false)['close_reason']);
    }

    public function test_the_journal_keeps_the_whole_history_of_a_card(): void
    {
        $this->withApiAuth($this->commandant());
        $first = $this->createPerson('Первый');
        $second = $this->createPerson('Второй');

        $this->postJson('/api/rfid-cards/bind', ['person_id' => $first->id, 'uid' => '0000000401'])->assertOk();
        $card = RfidCard::query()->firstWhere('uid', '0000000401');
        $this->postJson("/api/rfid-cards/{$card->id}/accept")->assertOk();
        $this->postJson('/api/rfid-cards/bind', ['person_id' => $second->id, 'uid' => '0000000401'])->assertOk();

        // Прежде карта помнила только последнего владельца, и «у кого она была
        // в марте» ответить было нечем.
        $rows = collect($this->getJson('/api/rfid-cards/journal?rfid_card_id='.$card->id)->assertOk()->json('data'));

        $this->assertCount(2, $rows);
        $this->assertSame($second->id, $rows->firstWhere('is_open', true)['person']['id']);
        $this->assertSame('returned', $rows->firstWhere('is_open', false)['close_reason']);
    }

    public function test_an_unbound_card_goes_to_someone_else(): void
    {
        $this->withApiAuth($this->commandant());
        $left = $this->createPerson('Уволившийся');
        $next = $this->createPerson('Следующий');

        $this->postJson('/api/rfid-cards/bind', ['person_id' => $left->id, 'uid' => '0000000501'])->assertOk();
        $card = RfidCard::query()->firstWhere('uid', '0000000501');

        // Карта осталась у уволившегося: физически не принимали, но числиться
        // за ним она больше не должна.
        $this->postJson("/api/rfid-cards/{$card->id}/release", ['reason' => 'left'])
            ->assertOk()
            ->assertJsonPath('data.status', 'stock')
            ->assertJsonPath('data.person_id', null);

        $this->postJson('/api/rfid-cards/bind', ['person_id' => $next->id, 'uid' => '0000000501'])
            ->assertOk()
            ->assertJsonPath('data.person_id', $next->id);

        $rows = collect($this->getJson('/api/rfid-cards/journal?rfid_card_id='.$card->id)->assertOk()->json('data'));
        $this->assertSame('left', $rows->firstWhere('is_open', false)['close_reason']);
    }

    public function test_the_journal_filters_by_open_and_closed_issues(): void
    {
        $this->withApiAuth($this->commandant());
        $first = $this->createPerson('Открытый');
        $second = $this->createPerson('Закрытый');

        $this->postJson('/api/rfid-cards/bind', ['person_id' => $first->id, 'uid' => '0000000801'])->assertOk();
        $this->postJson('/api/rfid-cards/bind', ['person_id' => $second->id, 'uid' => '0000000802'])->assertOk();
        $closed = RfidCard::query()->firstWhere('uid', '0000000802');
        $this->postJson("/api/rfid-cards/{$closed->id}/accept")->assertOk();

        // Фильтр «только на руках» приходит единицей и нулём. Со словом `true`
        // проверка падала, и человек видел служебное `validation.boolean`.
        $open = $this->getJson('/api/rfid-cards/journal?open=1')->assertOk()->json('data');
        $this->assertCount(1, $open);
        $this->assertTrue($open[0]['is_open']);

        $done = $this->getJson('/api/rfid-cards/journal?open=0')->assertOk()->json('data');
        $this->assertCount(1, $done);
        $this->assertFalse($done[0]['is_open']);

        $this->assertCount(2, $this->getJson('/api/rfid-cards/journal')->assertOk()->json('data'));
    }

    public function test_a_card_nobody_ever_held_is_deleted(): void
    {
        $this->withApiAuth($this->commandant());
        $card = $this->createCard('0000000601');

        // Заведена по ошибке или с опечаткой в номере — удаляется без следа.
        $this->deleteJson("/api/rfid-cards/{$card->id}")->assertNoContent();

        $this->assertNull(RfidCard::query()->find($card->id));
    }

    public function test_a_card_on_someones_hands_is_not_deleted_until_it_is_settled(): void
    {
        $this->withApiAuth($this->commandant());
        $person = $this->createPerson('Историчный');

        $this->postJson('/api/rfid-cards/bind', ['person_id' => $person->id, 'uid' => '0000000701'])->assertOk();
        $card = RfidCard::query()->firstWhere('uid', '0000000701');

        // Карта ходит по зданию: стереть её значило бы потерять след живой карты.
        $this->deleteJson("/api/rfid-cards/{$card->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.card.0', 'Карта сейчас на руках у человека. Сначала примите её или отвяжите — потом можно удалять.');

        // А ошибочную запись — например, с опечаткой в номере — убрать надо:
        // списанная так и осталась бы висеть в реестре и путать.
        $this->postJson("/api/rfid-cards/{$card->id}/accept")->assertOk();
        $this->deleteJson("/api/rfid-cards/{$card->id}")->assertNoContent();

        $this->assertNull(RfidCard::query()->find($card->id));
        $this->assertSame(0, RfidCardIssue::query()->where('rfid_card_id', $card->id)->count());
    }

    public function test_the_journal_is_exported_as_a_workbook(): void
    {
        $this->withApiAuth($this->commandant());
        $person = $this->createPerson('Выгружаемый');
        $this->postJson('/api/rfid-cards/bind', ['person_id' => $person->id, 'uid' => '0000000901'])->assertOk();

        $response = $this->get('/api/rfid-cards/journal/export');

        $response->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // Книга xlsx — это zip: начинается с PK.
        $this->assertStringStartsWith('PK', $response->getContent());
    }

    public function test_the_short_reader_format_is_refused_instead_of_guessing(): void
    {
        $this->withApiAuth($this->commandant());

        // «3+5» несёт не весь номер: дополнение нулями дало бы чужую карту.
        $this->postJson('/api/rfid-cards', ['uid' => '0009,12345'])
            ->assertStatus(422)
            ->assertJsonPath('errors.uid.0', 'Считыватель отдаёт номер в формате «3+5» — он короче настоящего, и по нему карты можно перепутать. Переключите считыватель на десятичный формат из 10 цифр.');
    }

    public function test_the_personnel_office_issues_cards_too(): void
    {
        $this->seed(RoleSeeder::class);

        $role = Role::query()->where('code', 'hr')->first();

        $this->assertNotNull($role, 'Роль отдела кадров не заведена');
        foreach (['rfid.cards.view', 'rfid.cards.manage'] as $code) {
            $this->assertTrue($role->permissions()->where('code', $code)->exists(), "Отделу кадров не выдано {$code}");
        }
    }

    public function test_the_people_list_shows_only_what_the_issue_needs(): void
    {
        $this->withApiAuth($this->commandant());
        $this->createPerson('Искомый');

        $rows = $this->getJson('/api/rfid-cards/people?search=Искомый')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('Искомый Проверочный', $rows[0]['full_name']);
        $this->assertNull($rows[0]['card'], 'У человека без карты она и не должна показываться');
        // Паспорт, телефон и адрес для выдачи карты не нужны и не отдаются.
        foreach (['phone', 'birth_date', 'snils', 'email'] as $field) {
            $this->assertArrayNotHasKey($field, $rows[0]);
        }
    }

    /**
     * Список групп несёт специальность, иначе группировка молча не случается.
     *
     * Выпадающий список групп разбит заголовками по специальности — решение
     * владельца от 23.08.2026: групп 69, и без заголовков в них не найтись.
     * Заголовки строит `buildGroupOptions` по полю `specialty`; нет поля — у всех
     * групп специальность считается пустой, все они ложатся под один заголовок
     * «Без специальности», и группировки не происходит вовсе.
     *
     * Ловится это только глазами и только если знать, чего ждать: список
     * рисуется, ищется и работает. На экране карт решение владельца так и не
     * исполнялось ни дня — замечено 24.08.2026 по печатной ведомости, в шапку
     * которой попал тот самый единственный заголовок.
     */
    public function test_the_group_list_carries_the_specialty_it_is_grouped_by(): void
    {
        $this->withApiAuth($this->commandant());

        Group::create([
            'name' => 'Хореографическое творчество, набор 2026',
            'specialty' => '51.02.01 Народное художественное творчество',
            'course' => 1,
            'year_start' => 2026,
        ]);

        $row = $this->getJson('/api/rfid-cards/groups')->assertOk()->json('data.0');

        $this->assertArrayHasKey('specialty', $row, 'Без специальности список групп не сгруппировать');
        $this->assertSame('51.02.01 Народное художественное творчество', $row['specialty']);
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
