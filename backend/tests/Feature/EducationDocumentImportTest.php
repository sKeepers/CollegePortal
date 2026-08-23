<?php

namespace Tests\Feature;

use App\Models\Admissions\EducationDocument;
use App\Models\Group;
use App\Models\Person;
use App\Models\ReferenceCatalog;
use App\Models\ReferenceItem;
use App\Models\Student;
use App\Services\Import\EducationDocumentImportHandler;
use App\Services\UniversalImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Загрузка аттестатов файлом.
 *
 * Данные вымышленные. Аттестат — последний недостающий слой личного дела: без
 * него ни одна карточка не проходит проверку ФРДО, поэтому загрузка заведена
 * заранее, до того как аттестаты соберут.
 */
class EducationDocumentImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_template_names_the_school_as_a_column_of_its_own(): void
    {
        $headers = $this->handler()->templateHeaders();

        $this->assertContains('Учебное заведение', $headers);
        $this->assertContains('Серия', $headers);
        $this->assertContains('Номер личного дела', $headers);
        $this->assertSameSize($headers, $this->handler()->templateExample());
    }

    public function test_it_finds_the_student_by_the_personal_file(): void
    {
        $student = $this->makeStudent('К', '528');

        $errors = $this->handler()->businessValidationErrors($this->row([
            'personal_file_letter' => 'К',
            'personal_file_number' => '528',
        ]));

        $this->assertSame([], $errors);
        $this->handler()->import($this->row([
            'personal_file_letter' => 'К',
            'personal_file_number' => '528',
        ]), 'create');

        $document = EducationDocument::query()->where('person_id', $student->person_id)->firstOrFail();
        $this->assertSame('07АА', $document->series);
        $this->assertSame('МБОУ СОШ № 44', $document->document_organization);
        $this->assertSame(2023, (int) $document->graduation_year);
    }

    /** Номер дела сам по себе ничего не значит: у каждой буквы своя нумерация. */
    public function test_the_letter_and_the_number_are_one_key(): void
    {
        $this->makeStudent('К', '528');
        $this->makeStudent('Л', '528', 'Никитин', 'Артём');

        $errors = $this->handler()->businessValidationErrors($this->row([
            'personal_file_letter' => 'Л',
            'personal_file_number' => '528',
        ]));

        $this->assertSame([], $errors);
    }

    public function test_it_falls_back_to_the_name_and_the_birth_date(): void
    {
        $student = $this->makeStudent('К', '528');

        $errors = $this->handler()->businessValidationErrors($this->row([
            'last_name' => 'Ковалёва',
            'first_name' => 'Полина',
            'birth_date' => '2008-03-14',
        ]));

        $this->assertSame([], $errors);
        $this->handler()->import($this->row([
            'last_name' => 'Ковалёва',
            'first_name' => 'Полина',
            'birth_date' => '2008-03-14',
        ]), 'create');

        $this->assertSame(1, EducationDocument::query()->where('person_id', $student->person_id)->count());
    }

    public function test_a_row_that_matches_nobody_is_reported_before_the_load(): void
    {
        $this->makeStudent('К', '528');

        $errors = $this->handler()->businessValidationErrors($this->row([
            'personal_file_letter' => 'Я',
            'personal_file_number' => '999',
        ]));

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Студент не найден', $errors['personal_file_number'][0]);
    }

    /**
     * Строка с одной только школой — это и есть 580 названий, которым сейчас
     * некуда лечь. Отказ обязан объяснять причину, а не молчать.
     */
    public function test_a_school_without_a_series_is_refused_with_a_reason(): void
    {
        $this->makeStudent('К', '528');

        $errors = $this->handler()->businessValidationErrors($this->row([
            'personal_file_letter' => 'К',
            'personal_file_number' => '528',
            'series' => '',
            'number' => '',
        ]));

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('только учебное заведение', $errors['series'][0]);
    }

    public function test_the_average_score_and_the_attachment_travel_too(): void
    {
        $student = $this->makeStudent('К', '528');

        $this->handler()->import($this->row([
            'personal_file_letter' => 'К',
            'personal_file_number' => '528',
            'average_score' => 4.35,
            'has_attachment' => true,
        ]), 'create');

        $document = EducationDocument::query()->where('person_id', $student->person_id)->firstOrFail();
        $this->assertSame(4.35, (float) $document->average_score);
        $this->assertTrue((bool) $document->has_attachment);
    }

    public function test_the_kind_of_the_certificate_comes_from_the_reference_book(): void
    {
        $catalog = ReferenceCatalog::firstOrCreate(
            ['code' => 'admission_education_document_types'],
            ['name' => 'Виды документов об образовании'],
        );
        $secondary = ReferenceItem::firstOrCreate(
            ['catalog_id' => $catalog->id, 'code' => 'secondary_general_certificate'],
            ['name' => 'Аттестат о среднем общем образовании'],
        );
        $student = $this->makeStudent('К', '528');

        $this->handler()->import($this->row([
            'personal_file_letter' => 'К',
            'personal_file_number' => '528',
            'document_type' => 'Аттестат о среднем общем образовании',
        ]), 'create');

        $document = EducationDocument::query()->where('person_id', $student->person_id)->firstOrFail();
        $this->assertSame($secondary->id, $document->document_type_id);
    }

    public function test_the_type_is_offered_by_the_universal_import(): void
    {
        $types = collect(app(UniversalImportService::class)->config()['types'] ?? []);

        $type = $types->firstWhere('value', 'education_documents');
        $this->assertNotNull($type, 'Тип не предлагается универсальным импортом.');
        $this->assertSame('Документы об образовании', $type['label']);
    }

    /**
     * Путь целиком: файл — предпросмотр — пробный проход — загрузка. Проверяется
     * не обработчик в одиночку, а то, чем в этот день будет пользоваться человек.
     */
    public function test_the_whole_path_from_the_file_to_the_load(): void
    {
        $student = $this->makeStudent('К', '528');
        $service = app(UniversalImportService::class);

        $csv = implode("\n", [
            'Буква личного дела;Номер личного дела;Серия;Номер;Дата выдачи;Учебное заведение;Год окончания',
            'К;528;07АА;0012345;20.06.2023;МБОУ СОШ № 44;2023',
            'Я;999;07АА;0012346;20.06.2023;МБОУ СОШ № 7;2023',
        ]);
        $file = UploadedFile::fake()->createWithContent('attestats.csv', $csv);

        $job = $service->createPreview($file, 'education_documents', null);
        $mapping = [
            'personal_file_letter' => 'Буква личного дела',
            'personal_file_number' => 'Номер личного дела',
            'series' => 'Серия',
            'number' => 'Номер',
            'issue_date' => 'Дата выдачи',
            'document_organization' => 'Учебное заведение',
            'graduation_year' => 'Год окончания',
        ];

        // Пробный проход: вторая строка обязана отвалиться до записи.
        $checked = $service->validateJob($job, $mapping, 'create');
        $this->assertSame(2, $checked->total_rows);
        $this->assertSame(1, $checked->error_count);
        $this->assertSame(0, EducationDocument::query()->count(), 'Пробный проход ничего не пишет.');

        $done = $service->confirmJob($checked, $mapping, 'create');
        $this->assertSame(1, $done->created_count);
        $this->assertSame(1, EducationDocument::query()->count());
        $this->assertSame(
            'МБОУ СОШ № 44',
            EducationDocument::query()->where('person_id', $student->person_id)->value('document_organization'),
        );
    }

    private function handler(): EducationDocumentImportHandler
    {
        return app(EducationDocumentImportHandler::class);
    }

    /** @param array<string, mixed> $extra */
    private function row(array $extra): array
    {
        return $this->handler()->prepare(array_merge([
            'personal_file_letter' => '',
            'personal_file_number' => '',
            'last_name' => '',
            'first_name' => '',
            'middle_name' => '',
            'birth_date' => '',
            'document_type' => '',
            'series' => '07АА',
            'number' => '0012345',
            'issue_date' => '20.06.2023',
            'document_organization' => 'МБОУ СОШ № 44',
            'graduation_year' => '2023',
            'average_score' => '',
            'has_attachment' => '',
        ], $extra));
    }

    private function makeStudent(string $letter, string $number, string $last = 'Ковалёва', string $first = 'Полина'): Student
    {
        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'last_name' => $last,
            'first_name' => $first,
            'birth_date' => '2008-03-14',
            'status' => 'active',
        ]);

        $group = Group::firstOrCreate(
            ['name' => 'Хореографическое творчество, набор 2023'],
            ['specialty' => 'Народное художественное творчество', 'year_start' => 2023],
        );

        return Student::create([
            'person_id' => $person->id,
            'group_id' => $group->id,
            'last_name' => $last,
            'first_name' => $first,
            'birth_date' => '2008-03-14',
            'status' => 'active',
            'personal_file_letter' => $letter,
            'personal_file_number' => $number,
        ]);
    }
}
