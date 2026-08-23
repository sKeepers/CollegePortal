<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Person;
use App\Models\Student;
use App\Services\StudentAddressCleanupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Телефон, прилипший к адресу при загрузке контингента.
 *
 * Данные вымышленные. Улица Тельмана здесь не для красоты: она есть в настоящих
 * карточках, и правило «резать по слову тел» без неё выглядит безопасным ровно до
 * первого такого адреса.
 */
class StudentAddressCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_moves_the_phone_out_of_the_address(): void
    {
        $student = $this->makeStudent('Ставрополь, улица Мира, д. 5, кв. 12 тел.8-988-123-45-67');

        $summary = app(StudentAddressCleanupService::class)->clean(apply: true);

        $this->assertSame(1, $summary['phone_written']);
        $student->refresh();
        $this->assertSame('Ставрополь, улица Мира, д. 5, кв. 12', $student->address);
        $this->assertSame('89881234567', $student->phone);
        $this->assertSame('89881234567', $student->person->refresh()->phone);
    }

    public function test_it_does_not_cut_a_street_named_like_the_word(): void
    {
        $student = $this->makeStudent('Ставрополь, улица Тельмана, д. 7');

        $summary = app(StudentAddressCleanupService::class)->clean(apply: true);

        $this->assertSame(0, $summary['phone_in_address']);
        $this->assertSame('Ставрополь, улица Тельмана, д. 7', $student->refresh()->address);
    }

    public function test_it_leaves_a_card_that_already_holds_another_number(): void
    {
        $student = $this->makeStudent('Ставрополь, улица Мира, д. 5 тел.8-988-123-45-67', '8-900-000-11-22');

        $summary = app(StudentAddressCleanupService::class)->clean(apply: true);

        $this->assertSame(1, $summary['skipped']);
        $this->assertSame('phone_conflict', $summary['issues'][0]['category']);
        $this->assertStringContainsString('тел', $student->refresh()->address);
    }

    public function test_it_leaves_a_tail_that_holds_two_numbers(): void
    {
        $this->makeStudent('Ставрополь, улица Мира, д. 5 тел.8-988-123-45-67, 8-900-000-11-22');

        $summary = app(StudentAddressCleanupService::class)->clean(apply: true);

        $this->assertSame(1, $summary['skipped']);
        $this->assertSame('several_phones', $summary['issues'][0]['category']);
    }

    public function test_an_address_that_is_only_a_phone_becomes_a_phone(): void
    {
        $student = $this->makeStudent('89659302901');

        $summary = app(StudentAddressCleanupService::class)->clean(apply: true);

        $this->assertSame(1, $summary['phone_written']);
        $student->refresh();
        $this->assertNull($student->address);
        $this->assertSame('89659302901', $student->phone);
    }

    public function test_it_moves_a_number_that_carries_no_marker(): void
    {
        // Ради этого случая правило и переписывали: 231 карточка из 233 написана
        // без слова «тел», и прежний проход их не видел вовсе.
        $student = $this->makeStudent('СК, г. Михайловск, ул. Гоголя, д.11, кв.15, 89881234567');

        $summary = app(StudentAddressCleanupService::class)->clean(apply: true);

        $this->assertSame(1, $summary['phone_written']);
        $student->refresh();
        $this->assertSame('СК, г. Михайловск, ул. Гоголя, д.11, кв.15', $student->address);
        $this->assertSame('89881234567', $student->phone);
        $this->assertSame('89881234567', $student->person->refresh()->phone);
        $this->assertSame('СК, г. Михайловск, ул. Гоголя, д.11, кв.15', $student->person->address);
    }

    public function test_it_leaves_a_row_where_text_follows_the_number(): void
    {
        $address = 'Ставрополь, улица Мира, д. 5, 89881234567, Переведена с ОДиУИ Пр№12 от 01.09.2025г';
        $student = $this->makeStudent($address);

        $summary = app(StudentAddressCleanupService::class)->clean(apply: true);

        $this->assertSame(1, $summary['skipped']);
        $this->assertSame('text_after_phone', $summary['issues'][0]['category']);
        $this->assertSame($address, $student->refresh()->address);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $student = $this->makeStudent('Ставрополь, улица Мира, д. 5, кв. 12 тел.8-988-123-45-67');

        $summary = app(StudentAddressCleanupService::class)->clean(apply: false);

        $this->assertSame(1, $summary['phone_written']);
        $this->assertStringContainsString('тел', $student->refresh()->address);
        $this->assertSame('', (string) $student->phone);
    }

    private function makeStudent(string $address, string $phone = ''): Student
    {
        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'last_name' => 'Ковалёва',
            'first_name' => 'Полина',
            'middle_name' => 'Сергеевна',
            'birth_date' => '2008-03-14',
            'address' => $address,
            'status' => 'active',
        ]);

        $group = Group::firstOrCreate(
            ['name' => 'Хореографическое творчество, набор 2026'],
            ['specialty' => 'Народное художественное творчество', 'course' => 1, 'year_start' => 2026],
        );

        return Student::create([
            'person_id' => $person->id,
            'group_id' => $group->id,
            'last_name' => 'Ковалёва',
            'first_name' => 'Полина',
            'middle_name' => 'Сергеевна',
            'birth_date' => '2008-03-14',
            'address' => $address,
            'phone' => $phone,
            'status' => 'active',
        ]);
    }
}
