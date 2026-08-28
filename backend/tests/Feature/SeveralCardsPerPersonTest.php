<?php

namespace Tests\Feature;

use App\Models\DigitalIdentity;
use App\Models\Employee;
use App\Models\Person;
use App\Models\RfidCard;
use App\Services\AccessCardResolver;
use App\Services\RfidCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * У человека бывает несколько карт.
 *
 * До 28.08.2026 портал считал иначе и отказывал во второй карте словами «у
 * человека уже есть карта на руках». Владелец сказал прямо: «на человека
 * оказалось записано больше одной карты, можешь объединить в человека и
 * привязать к нему эти карты». В кадровой выгрузке СКУД таких людей семь плюс
 * один, попавший в два файла с разными номерами.
 *
 * Отдельный вопрос, на который эти проверки отвечают замером, а не
 * рассуждением: **какая из нескольких карт открывает турникет**. Ответ — все.
 */
class SeveralCardsPerPersonTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_person_may_be_given_a_second_card(): void
    {
        $service = app(RfidCardService::class);
        $person = $this->person();

        $service->bind($person, '1111111');
        $service->bind($person, '2222222');

        $this->assertSame(2, RfidCard::query()
            ->where('person_id', $person->id)
            ->where('status', RfidCard::STATUS_ISSUED)
            ->count());
    }

    public function test_every_card_of_the_person_opens_the_turnstile(): void
    {
        $service = app(RfidCardService::class);
        $resolver = app(AccessCardResolver::class);
        $person = $this->person();
        $this->pass($person);

        $service->bind($person, '1111111');
        $service->bind($person, '2222222');

        // Разбор идёт от номера карты к человеку и его пропуску, а не от
        // человека к «его карте», — поэтому «действующая» здесь не одна.
        foreach (['0001111111', '0002222222'] as $uid) {
            $result = $resolver->resolve($uid);

            $this->assertNull($result['reason'], "Карта {$uid} не открыла турникет: ".(string) $result['reason']);
            $this->assertNotNull($result['identity']);
        }
    }

    public function test_accepting_one_card_back_leaves_the_other_working(): void
    {
        $service = app(RfidCardService::class);
        $resolver = app(AccessCardResolver::class);
        $person = $this->person();
        $this->pass($person);

        $first = $service->bind($person, '1111111');
        $service->bind($person, '2222222');

        // Комендант принял одну карту обратно. Раньше карта была одна, и
        // «принял» означало «проход закрыт». Теперь не означает, и это надо
        // видеть в поведении, а не выводить из кода.
        $service->accept($first);

        $this->assertNotNull($resolver->resolve('0001111111')['reason']);
        $this->assertNull($resolver->resolve('0002222222')['reason']);
    }

    public function test_the_person_card_shows_how_many_are_on_hands(): void
    {
        $service = app(RfidCardService::class);
        $person = $this->person();
        $service->bind($person, '1111111');
        $service->bind($person, '2222222');

        $person->load(['currentRfidCard', 'rfidCardsOnHands']);

        // Одна строка для показа и число рядом: без числа карточка человека
        // показывала бы одну карту и молчала об остальных.
        $this->assertNotNull($person->currentRfidCard);
        $this->assertSame(2, $person->rfidCardsOnHands->count());
    }

    private function person(): Person
    {
        return Person::create([
            'last_name' => 'Михайлов',
            'first_name' => 'Дмитрий',
            'middle_name' => 'Петрович',
            'status' => 'active',
        ]);
    }

    /**
     * Пропуск выдаётся не «человеку», а его карточке сотрудника.
     *
     * Первая редакция этого приспособления заводила пропуск с
     * `entity_type = 'person'`, и обе проверки прохода падали с «владелец
     * карты удалён из системы» — потому что `ownerExists()` знает только
     * студента, преподавателя и сотрудника, а всё остальное считает
     * несуществующим. Код был прав, ошибалось приспособление; на настоящих
     * данных стенда 20 карт из 236 разбираются без единого отказа.
     */
    private function pass(Person $person): DigitalIdentity
    {
        $employee = Employee::create([
            'person_id' => $person->id,
            'employee_number' => 'EMP-CARDS-'.$person->id,
            'status' => 'active',
            'employment_type' => 'full_time',
            'hired_at' => '2026-09-01',
        ]);

        return DigitalIdentity::create([
            'person_id' => $person->id,
            'entity_type' => DigitalIdentity::ENTITY_EMPLOYEE,
            'entity_id' => $employee->id,
            'token' => 'test-'.$person->id,
            'status' => DigitalIdentity::STATUS_ACTIVE,
            'issued_at' => now(),
        ]);
    }
}
