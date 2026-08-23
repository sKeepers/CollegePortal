<?php

namespace Tests\Feature;

use App\Models\Admissions\IdentityDocument;
use App\Models\Group;
use App\Models\Person;
use App\Models\ReferenceCatalog;
use App\Models\ReferenceItem;
use App\Models\Student;
use App\Services\StudentCsvService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Выгрузка студентов и вид документа, удостоверяющего личность.
 *
 * Колонки называются «Серия паспорта» и «Номер паспорта». Выгрузка берёт их из
 * документа человека, и пока документ один и российский, это верно. Иностранный
 * документ туда ехать не должен: выгрузкой переносят контингент на боевой
 * сервер, и при обратной загрузке он лёг бы в карточку российским паспортом.
 *
 * Данные вымышленные.
 */
class StudentCsvPassportTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_russian_passport_travels_in_the_passport_columns(): void
    {
        $student = $this->makeStudent();
        $this->makeDocument($student, 'russian_passport', 'Паспорт гражданина РФ', '0718', '456123');

        $csv = $this->export();

        $this->assertStringContainsString('0718', $csv);
        $this->assertStringContainsString('456123', $csv);
    }

    public function test_a_foreign_document_does_not_travel_as_a_passport(): void
    {
        $student = $this->makeStudent();
        $this->makeDocument($student, 'foreign_identity', 'Иностранный документ', 'АВ', '2156879');

        $csv = $this->export();

        $this->assertStringNotContainsString('2156879', $csv);
        // Сам студент в выгрузке остаётся — пустыми оказываются только реквизиты.
        $this->assertStringContainsString('Ковалёва', $csv);
    }

    /** Документ без вида ведёт себя как прежде: на установке справочник мог быть пуст. */
    public function test_a_document_without_a_kind_still_travels(): void
    {
        $student = $this->makeStudent();
        IdentityDocument::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $student->person_id,
            'series' => '0718',
            'number' => '999111',
            'is_primary' => true,
            'verification_status' => IdentityDocument::STATUS_RECEIVED,
        ]);

        $this->assertStringContainsString('999111', $this->export());
    }

    private function export(): string
    {
        $response = app(StudentCsvService::class)->export();
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    private function makeStudent(): Student
    {
        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'last_name' => 'Ковалёва',
            'first_name' => 'Полина',
            'birth_date' => '2008-03-14',
            'status' => 'active',
        ]);

        $group = Group::firstOrCreate(
            ['name' => 'Хореографическое творчество, набор 2026'],
            ['specialty' => 'Народное художественное творчество', 'year_start' => 2026],
        );

        return Student::create([
            'person_id' => $person->id,
            'group_id' => $group->id,
            'last_name' => 'Ковалёва',
            'first_name' => 'Полина',
            'birth_date' => '2008-03-14',
            'status' => 'active',
        ]);
    }

    private function makeDocument(Student $student, string $code, string $name, string $series, string $number): void
    {
        $catalog = ReferenceCatalog::firstOrCreate(
            ['code' => 'admission_identity_document_types'],
            ['name' => 'Виды документов, удостоверяющих личность'],
        );
        $type = ReferenceItem::firstOrCreate(
            ['catalog_id' => $catalog->id, 'code' => $code],
            ['name' => $name],
        );

        IdentityDocument::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $student->person_id,
            'document_type_id' => $type->id,
            'series' => $series,
            'number' => $number,
            'is_primary' => true,
            'verification_status' => IdentityDocument::STATUS_RECEIVED,
        ]);
    }
}
