<?php

namespace Tests\Feature;

use App\DTO\ProvisionedAccount;
use App\Models\Group;
use App\Models\Person;
use App\Models\Student;
use App\Services\AccountProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Логин строится из телефона только тогда, когда это телефон.
 *
 * 29.08.2026 при выдаче учётных записей группе из 27 человек двое получили
 * логины `999999999999` и `9999999999999999999999` — двадцать две цифры: в
 * карточке в одной клетке стояли два номера подряд. Такой логин человек не
 * наберёт и не воспроизведёт, а пароль показывается один раз, значит доступа у
 * него нет и узнает он об этом первого сентября.
 *
 * Данные вымышленные.
 */
class LoginFromPhoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_normal_phone_becomes_the_login(): void
    {
        $account = $this->provision('89991234567');

        $this->assertSame('+79991234567', $account->login);
    }

    public function test_ten_digits_without_a_country_code_are_a_phone_too(): void
    {
        $account = $this->provision('9991234567');

        $this->assertSame('+79991234567', $account->login);
    }

    public function test_two_phones_glued_together_do_not_become_a_login(): void
    {
        // Двадцать две цифры — ровно то, что видел владелец.
        $account = $this->provision('0508089002405541998877');

        $this->assertStringNotContainsString('0508089002405541', $account->login);
        $this->assertMatchesRegularExpression('/^[a-z0-9._-]+$/', $account->login);
    }

    public function test_a_number_of_the_wrong_length_does_not_become_a_login(): void
    {
        $account = $this->provision('999999999999');

        $this->assertNotSame('999999999999', $account->login);
    }

    public function test_without_a_usable_phone_the_login_comes_from_the_name(): void
    {
        $account = $this->provision('13');

        // Фамилия с инициалами: это и диктуется, и набирается.
        $this->assertStringStartsWith('semenova.', $account->login);
    }

    public function test_no_phone_at_all_behaves_the_same_way(): void
    {
        $account = $this->provision(null);

        $this->assertStringStartsWith('semenova.', $account->login);
    }

    private function provision(?string $phone): ProvisionedAccount
    {
        $group = Group::firstOrCreate(
            ['name' => 'Театральное творчество, набор 2026'],
            ['specialty' => 'Театральное творчество', 'year_start' => 2026],
        );

        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'last_name' => 'Семёнова',
            'first_name' => 'Дарья',
            'middle_name' => 'Сергеевна',
            'birth_date' => '2008-04-04',
            'phone' => $phone,
            'status' => 'active',
        ]);

        $student = Student::create([
            'person_id' => $person->id,
            'group_id' => $group->id,
            'course' => 1,
            'last_name' => 'Семёнова',
            'first_name' => 'Дарья',
            'middle_name' => 'Сергеевна',
            'birth_date' => '2008-04-04',
            'phone' => $phone,
            'status' => 'active',
        ]);

        return app(AccountProvisioningService::class)->provision($student);
    }
}
