<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Permission;
use App\Models\Person;
use App\Models\RfidCard;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Выдача карт очереди: что случится на 596 людях.
 *
 * Со следующей недели карты выдают всем студентам пофамильно, через считыватель:
 * человек стоит у стойки, его находят по фамилии, он подносит карту. Через
 * вкладку «Выдача» пройдут 596 человек, и всё, что на одном человеке выглядит
 * мелочью, повторится шестьсот раз.
 *
 * Замер 01.09.2026: здесь заморожено то, как портал ведёт себя в четырёх
 * случаях, которые в такой очереди случатся обязательно.
 */
class IssuingToAQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withApiAuth($this->commandant());
    }

    public function test_a_second_swipe_of_the_same_card_shows_its_owner_and_does_not_break(): void
    {
        // Считыватель читает карту в кадре несколько раз, человек мажет мимо и
        // подносит снова. Второй скан обязан быть безобидным.
        $person = $this->person('Первый');
        $this->postJson('/api/rfid-cards/bind', ['person_id' => $person->id, 'uid' => '0000000101'])->assertOk();

        // Ответ поиска карты лежит **в корне**, а не в `data`: у этой ручки
        // своя форма, и первая редакция проверки искала не там.
        $this->getJson('/api/rfid-cards/lookup?uid=0000000101')
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('person.full_name', 'Первый Пётр Петрович');

        $this->assertSame(1, RfidCard::count(), 'повторное чтение не должно заводить вторую карту');
    }

    public function test_a_second_card_for_the_same_person_goes_through(): void
    {
        // Запрет на вторую карту снят 30.08.2026 по слову владельца. В очереди
        // это случится: человек потерял карту, пришёл за новой.
        $person = $this->person('Двукарточный');
        $this->postJson('/api/rfid-cards/bind', ['person_id' => $person->id, 'uid' => '0000000101'])
            ->assertOk()
            ->assertJsonPath('meta.cards_still_out', []);

        // И выдача второй **называет первую**: оператор иначе не узнает, что у
        // человека две живые карты и старая по-прежнему открывает турникет.
        $this->postJson('/api/rfid-cards/bind', ['person_id' => $person->id, 'uid' => '0000000202'])
            ->assertOk()
            ->assertJsonPath('meta.cards_still_out', ['0000000101']);

        $this->assertSame(2, RfidCard::query()->where('person_id', $person->id)->where('status', RfidCard::STATUS_ISSUED)->count());
    }

    public function test_a_card_already_on_someone_elses_hands_is_refused(): void
    {
        // 636 строк CARDDEX на 596 студентов: номера повторяются, и карта,
        // которую подносит один, может числиться за другим.
        $first = $this->person('Первый');
        $second = $this->person('Второй');

        $this->postJson('/api/rfid-cards/bind', ['person_id' => $first->id, 'uid' => '0000000101'])->assertOk();

        $refusal = $this->postJson('/api/rfid-cards/bind', ['person_id' => $second->id, 'uid' => '0000000101'])
            ->assertStatus(422)
            ->json('errors.card.0');

        // Отказ обязан **называть владельца**: «примите её обратно» у стойки
        // означает «у кого?», и без имени совет невыполним.
        $this->assertStringContainsString('Первый', (string) $refusal);
        $this->assertStringContainsString('примите её обратно', (string) $refusal);
    }

    public function test_a_card_held_by_a_departed_student_says_so(): void
    {
        // Часть карт из выгрузки СКУД числится за теми, кого в колледже уже
        // нет: 636 строк на 596 человек. «Примите у него» тогда невыполнимо —
        // карту надо отметить возвращённой, и сообщение должно это сказать.
        $gone = Person::create(['last_name' => 'Выбывший', 'first_name' => 'Вадим', 'middle_name' => 'Петрович', 'status' => 'active']);
        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]);
        Student::create(['person_id' => $gone->id, 'group_id' => $group->id, 'last_name' => 'Выбывший', 'first_name' => 'Вадим', 'status' => 'expelled']);

        $this->postJson('/api/rfid-cards/bind', ['person_id' => $gone->id, 'uid' => '0000000101'])->assertOk();

        $refusal = (string) $this->postJson('/api/rfid-cards/bind', ['person_id' => $this->person('Новый')->id, 'uid' => '0000000101'])
            ->assertStatus(422)
            ->json('errors.card.0');

        $this->assertStringContainsString('выбыл', $refusal);
    }

    public function test_namesakes_are_told_apart_by_the_patronymic_in_the_picker(): void
    {
        // Среди 596 студентов есть четверо, различимых только отчеством. Если
        // подборщик их не различает, карта уйдёт не тому, и узнается это у
        // турникета.
        Person::create(['last_name' => 'Сидоренко', 'first_name' => 'Алина', 'middle_name' => 'Сергеевна', 'status' => 'active']);
        Person::create(['last_name' => 'Сидоренко', 'first_name' => 'Алина', 'middle_name' => 'Олеговна', 'status' => 'active']);

        $names = collect($this->getJson('/api/rfid-cards/people?search=Сидоренко')->assertOk()->json('data'))
            ->pluck('full_name')
            ->all();

        $this->assertCount(2, $names);
        $this->assertNotSame($names[0], $names[1], 'однофамильцы обязаны различаться в списке выбора');
        foreach ($names as $name) {
            $this->assertMatchesRegularExpression('/(Сергеевна|Олеговна)/u', $name, 'в списке должно быть отчество');
        }
    }

    private function person(string $lastName): Person
    {
        return Person::create(['last_name' => $lastName, 'first_name' => 'Пётр', 'middle_name' => 'Петрович', 'status' => 'active']);
    }

    private function commandant(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::query()->firstOrCreate(['code' => 'issuing_desk'], ['name' => 'Стойка выдачи', 'description' => 'Проба']);

        foreach (['rfid.cards.view', 'rfid.cards.manage', 'people.view'] as $code) {
            $permission = Permission::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $code, 'module' => 'Test', 'description' => $code, 'system' => true, 'active' => true],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->roles()->attach($role->id);

        return $user;
    }
}
