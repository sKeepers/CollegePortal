<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Person;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Перенос «бюджет / договор» из буквы названия группы в поле карточки.
 *
 * Данные вымышленные. Группа «Теория музыки, набор 2026» здесь не для полноты:
 * безбуквенных групп сорок шесть, и правило обязано пройти мимо них, а не
 * записать им бюджет по догадке.
 */
class FundingFormFromGroupLetterTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_letter_becomes_the_funding_form(): void
    {
        $budget = $this->makeStudent('Хореографическое творчество А, набор 2026');
        $paid = $this->makeStudent('Хореографическое творчество Б, набор 2026');

        $this->artisan('students:funding-from-group-letter --apply')->assertSuccessful();

        $this->assertSame('Бюджет', $budget->refresh()->funding_form);
        $this->assertSame('Договор', $paid->refresh()->funding_form);
    }

    public function test_a_group_without_a_letter_is_left_alone(): void
    {
        // Про эти 329 человек документ не говорит ничего, и приказ догадку не
        // подтверждает. Пустое поле честнее подставленного «Бюджет».
        $student = $this->makeStudent('Теория музыки, набор 2026');

        $this->artisan('students:funding-from-group-letter --apply')->assertSuccessful();

        $this->assertSame('', (string) $student->refresh()->funding_form);
    }

    public function test_a_letter_inside_the_specialty_name_is_not_a_marker(): void
    {
        $student = $this->makeStudent('Библиотечно-информационная деятельность, набор 2026');

        $this->artisan('students:funding-from-group-letter --apply')->assertSuccessful();

        $this->assertSame('', (string) $student->refresh()->funding_form);
    }

    public function test_a_filled_field_is_not_overwritten_and_a_clash_is_reported(): void
    {
        $student = $this->makeStudent('Хореографическое творчество А, набор 2026', 'Договор');

        $this->artisan('students:funding-from-group-letter --apply')
            ->expectsOutputToContain('буква группы говорит')
            ->assertSuccessful();

        $this->assertSame('Договор', $student->refresh()->funding_form);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $student = $this->makeStudent('Хореографическое творчество А, набор 2026');

        $this->artisan('students:funding-from-group-letter')->assertSuccessful();

        $this->assertSame('', (string) $student->refresh()->funding_form);
    }

    public function test_the_probe_stops_at_the_limit(): void
    {
        $first = $this->makeStudent('Хореографическое творчество А, набор 2026');
        $second = $this->makeStudent('Хореографическое творчество Б, набор 2026');

        $this->artisan('students:funding-from-group-letter --limit=1 --apply')->assertSuccessful();

        $touched = collect([$first, $second])
            ->filter(fn (Student $student): bool => filled($student->refresh()->funding_form))
            ->count();

        $this->assertSame(1, $touched);
    }

    private function makeStudent(string $groupName, string $funding = ''): Student
    {
        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'last_name' => 'Ковалёва',
            'first_name' => 'Полина',
            'birth_date' => '2008-03-14',
            'status' => 'active',
        ]);

        $group = Group::firstOrCreate(
            ['name' => $groupName],
            ['specialty' => 'Народное художественное творчество', 'course' => 1, 'year_start' => 2026],
        );

        return Student::create([
            'person_id' => $person->id,
            'group_id' => $group->id,
            'last_name' => 'Ковалёва',
            'first_name' => 'Полина',
            'birth_date' => '2008-03-14',
            'funding_form' => $funding,
            'status' => 'active',
        ]);
    }
}
