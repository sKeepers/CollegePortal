<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\DigitalIdentity;
use App\Models\Group;
use App\Models\Person;
use App\Models\RfidCard;
use App\Models\Student;
use App\Services\QrSvgService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Проход по RFID-карте.
 *
 * Карта — не второй пропуск, а второй способ предъявить тот же самый. Отсюда
 * главное свойство, ради которого всё и делалось: войти можно по QR, выйти по
 * карте, и направление не собьётся.
 */
class AccessGateCardScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_a_card_on_hands_opens_the_gate_and_alternates_direction(): void
    {
        ['card' => $card] = $this->personWithCard('0008327739');

        $this->postJson('/api/access/scan', ['token' => $card->uid, 'access_point' => 'Главный вход'])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_ALLOWED)
            ->assertJsonPath('data.direction', AccessEvent::DIRECTION_IN);

        // Мимо окна защиты от дублей: следующее прикладывание — это уже выход.
        $this->travel(5)->seconds();

        $this->postJson('/api/access/scan', ['token' => $card->uid])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_ALLOWED)
            ->assertJsonPath('data.direction', AccessEvent::DIRECTION_OUT);
    }

    public function test_the_card_and_the_qr_are_the_same_pass(): void
    {
        ['identity' => $identity, 'card' => $card] = $this->personWithCard('0008327740');
        $qr = app(QrSvgService::class);

        $this->postJson('/api/access/scan', ['token' => $qr->dynamicPayload($identity)['payload']])
            ->assertOk()
            ->assertJsonPath('data.direction', AccessEvent::DIRECTION_IN);

        $this->travel(5)->seconds();

        // Если бы карта заводила собственный пропуск, здесь снова был бы вход,
        // а человек навсегда остался бы в списке «кто в здании».
        $this->postJson('/api/access/scan', ['token' => $card->uid])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_ALLOWED)
            ->assertJsonPath('data.direction', AccessEvent::DIRECTION_OUT);

        $this->assertSame(2, AccessEvent::query()->where('digital_identity_id', $identity->id)->count());
    }

    public function test_a_card_left_on_the_reader_is_not_counted_twice(): void
    {
        ['card' => $card] = $this->personWithCard('0008327741');

        $first = $this->postJson('/api/access/scan', ['token' => $card->uid])
            ->assertOk()
            ->assertJsonPath('data.direction', AccessEvent::DIRECTION_IN)
            ->json('data.id');

        // У карты нет одноразовости: считыватель отдаёт номер, пока она лежит.
        // Без окна это записалось бы выходом, и человек «вышел» бы, не сходя с
        // места.
        $this->postJson('/api/access/scan', ['token' => $card->uid])
            ->assertOk()
            ->assertJsonPath('data.id', $first)
            ->assertJsonPath('data.direction', AccessEvent::DIRECTION_IN);

        $this->assertSame(1, AccessEvent::query()->count());
    }

    public function test_a_lost_card_is_refused_and_the_reason_names_it(): void
    {
        ['card' => $card] = $this->personWithCard('0008327742');
        $card->forceFill(['status' => RfidCard::STATUS_LOST, 'person_id' => null])->save();

        $this->postJson('/api/access/scan', ['token' => $card->uid])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_DENIED)
            ->assertJsonPath('data.reason', 'Карта 0008327742 числится утерянной.');
    }

    public function test_an_unknown_number_becomes_a_refusal_in_the_journal(): void
    {
        // Не 422: на проходной отказ обязан попасть в журнал и на экран
        // охранника, иначе снаружи это выглядит как «сканирования не было».
        $this->postJson('/api/access/scan', ['token' => '0009999999'])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_DENIED)
            ->assertJsonPath('data.reason', 'Карта 0009999999 не зарегистрирована.');

        $this->assertSame(1, AccessEvent::query()->where('result', AccessEvent::RESULT_DENIED)->count());
    }

    public function test_gibberish_is_refused_without_pretending_to_be_a_card(): void
    {
        $this->postJson('/api/access/scan', ['token' => 'привет'])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_DENIED)
            ->assertJsonPath('data.reason', 'Код не распознан. Это не QR-пропуск и не номер карты.');
    }

    public function test_a_refusal_does_not_flip_the_direction(): void
    {
        ['card' => $card] = $this->personWithCard('0008327743');

        $this->postJson('/api/access/scan', ['token' => $card->uid])->assertJsonPath('data.direction', AccessEvent::DIRECTION_IN);

        $this->travel(5)->seconds();
        $this->postJson('/api/access/scan', ['token' => '0009999998'])->assertJsonPath('data.result', AccessEvent::RESULT_DENIED);

        // Отказ — не проход: человек остался по ту же сторону двери.
        $this->travel(5)->seconds();
        $this->postJson('/api/access/scan', ['token' => $card->uid])
            ->assertOk()
            ->assertJsonPath('data.direction', AccessEvent::DIRECTION_OUT);
    }

    public function test_a_card_without_an_owner_is_refused(): void
    {
        ['card' => $card] = $this->personWithCard('0008327744');
        $card->forceFill(['person_id' => null])->save();

        $this->postJson('/api/access/scan', ['token' => $card->uid])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_DENIED)
            ->assertJsonPath('data.reason', 'Карта 0008327744 ни за кем не числится.');
    }

    public function test_the_number_is_understood_without_leading_zeros(): void
    {
        ['card' => $card] = $this->personWithCard('0008327745');

        // Считыватели дополняют номер нулями по-разному; карта одна и та же.
        $this->postJson('/api/access/scan', ['token' => '8327745'])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_ALLOWED);

        $this->assertSame($card->uid, '0008327745');
    }

    /**
     * @return array{person: Person, identity: DigitalIdentity, card: RfidCard}
     */
    private function personWithCard(string $uid): array
    {
        $person = Person::create([
            'last_name' => 'Проходов',
            'first_name' => 'Пётр',
            'status' => 'active',
        ]);

        $group = Group::create([
            'name' => 'ИСП-'.substr($uid, -3),
            'specialty' => 'Инструментальное исполнительство',
            'course' => 1,
            'year_start' => 2026,
        ]);

        $student = Student::create([
            'group_id' => $group->id,
            'person_id' => $person->id,
            'last_name' => 'Проходов',
            'first_name' => 'Пётр',
            'status' => 'active',
        ]);

        $identity = DigitalIdentity::create([
            'person_id' => $person->id,
            'entity_type' => DigitalIdentity::ENTITY_STUDENT,
            'entity_id' => $student->id,
            'token' => (string) Str::uuid(),
            'status' => DigitalIdentity::STATUS_ACTIVE,
            'issued_at' => now(),
        ]);

        $card = RfidCard::create([
            'uid' => $uid,
            'person_id' => $person->id,
            'status' => RfidCard::STATUS_ISSUED,
            'issued_at' => now(),
        ]);

        return ['person' => $person, 'identity' => $identity, 'card' => $card];
    }
}
