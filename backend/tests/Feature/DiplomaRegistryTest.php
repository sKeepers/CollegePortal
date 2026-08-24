<?php

namespace Tests\Feature;

use App\Models\Diploma;
use App\Models\DiplomaBlank;
use App\Models\Graduate;
use App\Models\Group;
use App\Models\Person;
use App\Models\Student;
use App\Services\Graduation\DiplomaBlankService;
use App\Services\Graduation\DiplomaRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Книга регистрации выданных дипломов.
 *
 * Данные вымышленные. Книга ведётся по закону и по ней отвечают на запросы о
 * подлинности, поэтому проверяется не «что-то вернулось», а порядок строк и
 * честность пустых граф.
 */
class DiplomaRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_issued_diplomas_reach_the_book(): void
    {
        $this->makeDiploma('12', issued: true);
        $this->makeDiploma('13', issued: false);

        $rows = app(DiplomaRegistryService::class)->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('12', $rows[0]['registration_number']);
    }

    public function test_the_book_is_ordered_by_number_not_by_string(): void
    {
        // «10» между «1» и «2» — самая заметная поломка книги: по ней ищут
        // глазами, а не запросом.
        foreach (['2', '10', '1'] as $number) {
            $this->makeDiploma($number, issued: true);
        }

        $rows = app(DiplomaRegistryService::class)->rows();

        $this->assertSame(['1', '2', '10'], $rows->pluck('registration_number')->all());
    }

    public function test_a_diploma_without_a_number_goes_to_the_end_and_is_not_hidden(): void
    {
        $this->makeDiploma('5', issued: true);
        $this->makeDiploma(null, issued: true);

        $rows = app(DiplomaRegistryService::class)->rows();

        $this->assertCount(2, $rows, 'диплом без номера обязан быть виден: пропуск в нумерации замечают, спрятанную строку нет');
        $this->assertSame('5', $rows[0]['registration_number']);
        $this->assertNull($rows[1]['registration_number']);
    }

    public function test_the_supplement_column_takes_the_blank_number(): void
    {
        $graduate = $this->makeDiploma('7', issued: true);

        $batch = app(DiplomaBlankService::class)->receive([
            'kind' => DiplomaBlank::KIND_SUPPLEMENT,
            'series' => '115932',
            'number_from' => '0001',
            'number_to' => '0002',
            'received_at' => '2026-08-24',
        ]);

        app(DiplomaBlankService::class)->assign($batch->blanks()->orderBy('number')->first(), $graduate);

        $rows = app(DiplomaRegistryService::class)->rows();

        $this->assertSame('115932 0001', $rows[0]['supplement_blank']);
    }

    public function test_a_missing_supplement_stays_empty_rather_than_borrowing_the_diploma_number(): void
    {
        $this->makeDiploma('8', issued: true);

        $rows = app(DiplomaRegistryService::class)->rows();

        $this->assertNull($rows[0]['supplement_blank']);
    }

    public function test_the_years_list_holds_only_years_the_book_has(): void
    {
        $this->makeDiploma('1', issued: true, year: 2025);
        $this->makeDiploma('2', issued: true, year: 2026);
        $this->makeDiploma('3', issued: false, year: 2024);

        $this->assertSame([2026, 2025], app(DiplomaRegistryService::class)->years());
    }

    private function makeDiploma(?string $registrationNumber, bool $issued, int $year = 2026): Graduate
    {
        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'last_name' => 'Ковалёва',
            'first_name' => 'Полина',
            'birth_date' => '2005-03-14',
            'status' => 'active',
        ]);

        $group = Group::firstOrCreate(
            ['name' => 'Хореографическое творчество, набор '.($year - 4)],
            ['specialty' => 'Народное художественное творчество', 'year_start' => $year - 4],
        );

        $student = Student::create([
            'person_id' => $person->id,
            'group_id' => $group->id,
            'last_name' => 'Ковалёва',
            'first_name' => 'Полина',
            'birth_date' => '2005-03-14',
            'status' => 'graduated',
        ]);

        $graduate = Graduate::create([
            'person_id' => $person->id,
            'student_id' => $student->id,
            'group_id' => $group->id,
            'graduation_year' => $year,
            'qualification' => 'Руководитель любительского творческого коллектива',
            'status' => 'draft',
        ]);

        Diploma::create([
            'graduate_id' => $graduate->id,
            'registration_number' => $registrationNumber,
            'issue_date' => $issued ? $year.'-06-30' : null,
            'status' => $issued ? 'issued' : 'draft',
        ]);

        return $graduate->load('diploma');
    }
}
