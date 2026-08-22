<?php

namespace Tests\Feature;

use App\Models\Admissions\IdentityDocument;
use App\Models\Group;
use App\Models\Person;
use App\Models\ReferenceCatalog;
use App\Models\ReferenceItem;
use App\Models\Student;
use App\Services\Import\FisStudentEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Дополнение карточек студентов из выгрузки ФИС ГИА.
 *
 * Данные здесь вымышленные — настоящие лежат вне репозитория. СНИЛС подобраны с
 * верным контрольным числом: `SnilsService` отказывает неверным, и тест на
 * случайных одиннадцати цифрах проверял бы только отказ.
 */
class FisStudentEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private const SNILS_ONE = '11223344595';

    private const SNILS_TWO = '22334455639';

    /** Колонки выгрузки ФИС в том порядке, в каком их отдаёт сервис. */
    private const HEADERS = [
        '№ заявления', 'Статус', 'ФИО', 'Наименование приказа', 'Номер приказа',
        'Документ, удостоверяющий личность', 'Кем выдан документ, удостоверяющий личность',
        'Дата выдачи документа, удостоверяющего личность',
        'Код подразделения, выдавшего документ, удостоверяющий личность',
        'Гражданство', 'Пол', 'Дата рождения', 'Место рождения', 'Регион',
        'Тип населённого пункта', 'Адрес', 'СНИЛС', 'E-Mail',
    ];

    public function test_it_fills_what_is_empty_and_leaves_what_is_filled(): void
    {
        $student = $this->makeStudent('Ковалёва', 'Полина', 'Сергеевна', '2008-03-14', [
            'address' => 'Ставрополь, улица Мира, 5',
        ]);

        $path = $this->makeExport([
            $this->row('Ковалёва Полина Сергеевна', '14.03.2008', self::SNILS_ONE, [
                'passport' => '0718 456123',
                'citizenship' => 'Российская Федерация',
                'gender' => 'Женский',
                'address' => 'улица Ленина, 12',
                'region' => 'Ставропольский край',
                'order_number' => '106',
            ]),
        ]);

        $summary = $this->service()->enrich(
            [['path' => $path, 'label' => '2023.xls', 'order_date' => '2023-08-18']],
            apply: true,
        );

        $this->assertSame(1, $summary['matched']);

        $student->refresh();
        $this->assertSame('112-233-445 95', $student->snils);
        $this->assertSame('0718', $student->passport_series);
        $this->assertSame('456123', $student->passport_number);
        $this->assertSame('106', $student->enrollment_order_number);
        $this->assertSame('2023-08-18', $student->enrollment_order_date->toDateString());

        // Адрес в портале уже стоял — выгрузка его не переписывает, а сообщает
        // о расхождении: какой из двух верен, решает человек.
        $this->assertSame('Ставрополь, улица Мира, 5', $student->address);
        $this->assertSame(1, $summary['conflicts']['students.address'] ?? 0);

        $person = $student->person->refresh();
        $this->assertSame('female', $person->gender);
        $this->assertSame('Российская Федерация', $person->citizenship);
        $this->assertSame('112-233-445 95', $person->snils);
        $this->assertSame(hash('sha256', self::SNILS_ONE), $person->snils_hash);
    }

    public function test_it_never_creates_a_student_for_a_row_without_a_match(): void
    {
        $this->makeStudent('Ковалёва', 'Полина', 'Сергеевна', '2008-03-14');

        $path = $this->makeExport([
            $this->row('Никитин Артём Павлович', '02.06.2007', self::SNILS_TWO),
        ]);

        $summary = $this->service()->enrich(
            [['path' => $path, 'label' => '2023.xls', 'order_date' => null]],
            apply: true,
        );

        $this->assertSame(1, $summary['not_found']);
        $this->assertSame(1, Student::query()->count());
        $this->assertSame('not_found', $summary['issues'][0]['category']);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $student = $this->makeStudent('Ковалёва', 'Полина', 'Сергеевна', '2008-03-14');

        $path = $this->makeExport([
            $this->row('Ковалёва Полина Сергеевна', '14.03.2008', self::SNILS_ONE, ['passport' => '0718 456123']),
        ]);

        $summary = $this->service()->enrich(
            [['path' => $path, 'label' => '2023.xls', 'order_date' => null]],
            apply: false,
        );

        $this->assertSame(1, $summary['written']['students.snils'] ?? 0);
        $this->assertNull($student->refresh()->snils);
        $this->assertNull($student->person->refresh()->snils);
        $this->assertSame(0, IdentityDocument::query()->count());
    }

    public function test_the_snils_of_the_person_is_never_overwritten(): void
    {
        $student = $this->makeStudent('Ковалёва', 'Полина', 'Сергеевна', '2008-03-14');
        $student->person->forceFill([
            'snils' => '223-344-556 39',
            'snils_hash' => hash('sha256', self::SNILS_TWO),
        ])->save();

        $path = $this->makeExport([
            $this->row('Ковалёва Полина Сергеевна', '14.03.2008', self::SNILS_ONE),
        ]);

        $summary = $this->service()->enrich(
            [['path' => $path, 'label' => '2023.xls', 'order_date' => null]],
            apply: true,
        );

        // В карточку человека — не переписан и вынесен в расхождения.
        $this->assertSame('223-344-556 39', $student->person->refresh()->snils);
        $this->assertSame(1, $summary['conflicts']['snils_person'] ?? 0);

        // В учебную карточку — записан: там это реквизит, а не опознание личности.
        $this->assertSame('112-233-445 95', $student->refresh()->snils);
    }

    public function test_the_passport_lands_in_the_documents_of_the_person(): void
    {
        $this->passportDocumentType();
        $student = $this->makeStudent('Ковалёва', 'Полина', 'Сергеевна', '2008-03-14');

        $path = $this->makeExport([
            $this->row('Ковалёва Полина Сергеевна', '14.03.2008', self::SNILS_ONE, [
                'passport' => '0718 456123',
                'passport_issued_at' => '20.03.2022',
                'passport_issuer' => 'ГУ МВД России по Ставропольскому краю',
                'passport_department_code' => '260-001',
            ]),
        ]);

        $this->service()->enrich(
            [['path' => $path, 'label' => '2023.xls', 'order_date' => null]],
            apply: true,
        );

        $document = IdentityDocument::query()->where('person_id', $student->person_id)->firstOrFail();
        $this->assertSame('0718', $document->series);
        $this->assertSame('456123', $document->number);
        $this->assertSame('260-001', $document->subdivision_code);
        $this->assertSame('2022-03-20', $document->issue_date->toDateString());
        $this->assertSame('260-001', $student->refresh()->passport_department_code);
    }

    public function test_a_foreign_document_leaves_the_passport_fields_alone(): void
    {
        $student = $this->makeStudent('Саркисян', 'Ани', 'Ашотовна', '2007-11-09');

        $path = $this->makeExport([
            $this->row('Саркисян Ани Ашотовна', '09.11.2007', self::SNILS_ONE, [
                'passport' => 'АС 1234567',
                'passport_issued_at' => '15.05.2021',
                'citizenship' => 'Армения, Республика',
            ]),
        ]);

        $summary = $this->service()->enrich(
            [['path' => $path, 'label' => '2024.xls', 'order_date' => null]],
            apply: true,
        );

        $student->refresh();
        $this->assertNull($student->passport_series);
        $this->assertNull($student->passport_number);
        // Одинокая дата выдачи ни к какому документу не относится — не пишем.
        $this->assertNull($student->passport_issue_date);
        $this->assertSame('Армения, Республика', $student->person->refresh()->citizenship);
        $this->assertSame('passport_unparsed', $summary['issues'][0]['category']);
    }

    /**
     * Фамилия сменилась после подачи заявления — автомат такую строку не берёт,
     * и правильно делает. Разобрав случай, человек назначает пару сам.
     */
    public function test_a_pair_named_by_hand_beats_the_matching(): void
    {
        $student = $this->makeStudent('Никитина', 'Полина', 'Сергеевна', '2008-03-14');

        $path = $this->makeExport([
            $this->row('Ковалёва Полина Сергеевна', '14.03.2008', self::SNILS_ONE, ['passport' => '0718 456123']),
        ]);

        $summary = $this->service()->enrich(
            [['path' => $path, 'label' => '2023.xls', 'order_date' => null]],
            apply: true,
            pairs: ['2023.xls:2' => $student->id],
        );

        $this->assertSame(1, $summary['matched_by_hand']);
        $this->assertSame(0, $summary['not_found']);
        $this->assertSame('112-233-445 95', $student->refresh()->snils);
        $this->assertSame('0718', $student->passport_series);

        // ФИО из выгрузки не переносится: пару назначили, чтобы дописать СНИЛС и
        // паспорт, а не чтобы переименовать человека.
        $this->assertSame('Никитина', $student->last_name);
    }

    public function test_a_pair_pointing_at_nobody_is_reported_and_writes_nothing(): void
    {
        $student = $this->makeStudent('Никитина', 'Полина', 'Сергеевна', '2008-03-14');

        $path = $this->makeExport([
            $this->row('Ковалёва Полина Сергеевна', '14.03.2008', self::SNILS_ONE),
        ]);

        $summary = $this->service()->enrich(
            [['path' => $path, 'label' => '2023.xls', 'order_date' => null]],
            apply: true,
            pairs: ['2023.xls:2' => $student->id + 1000],
        );

        $this->assertSame(1, $summary['not_found']);
        $this->assertNull($student->refresh()->snils);
    }

    public function test_the_probe_takes_only_the_first_rows(): void
    {
        $first = $this->makeStudent('Ковалёва', 'Полина', 'Сергеевна', '2008-03-14');
        $second = $this->makeStudent('Никитин', 'Артём', 'Павлович', '2007-06-02');

        $path = $this->makeExport([
            $this->row('Ковалёва Полина Сергеевна', '14.03.2008', self::SNILS_ONE),
            $this->row('Никитин Артём Павлович', '02.06.2007', self::SNILS_TWO),
        ]);

        $summary = $this->service()->enrich(
            [['path' => $path, 'label' => '2023.xls', 'order_date' => null]],
            apply: true,
            limit: 1,
        );

        $this->assertSame(1, $summary['rows_processed']);
        $this->assertNotNull($first->refresh()->snils);
        $this->assertNull($second->refresh()->snils);
    }

    private function service(): FisStudentEnrichmentService
    {
        return app(FisStudentEnrichmentService::class);
    }

    /** @param array<string, mixed> $extra */
    private function makeStudent(string $last, string $first, string $middle, string $birthDate, array $extra = []): Student
    {
        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'last_name' => $last,
            'first_name' => $first,
            'middle_name' => $middle,
            'birth_date' => $birthDate,
            'status' => 'active',
        ]);

        $group = Group::firstOrCreate(
            ['name' => 'Хореографическое творчество, набор 2023'],
            ['specialty' => 'Народное художественное творчество', 'course' => 1, 'year_start' => 2023],
        );

        return Student::create([
            'person_id' => $person->id,
            'group_id' => $group->id,
            'last_name' => $last,
            'first_name' => $first,
            'middle_name' => $middle,
            'birth_date' => $birthDate,
            'status' => 'active',
        ] + $extra);
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function row(string $fio, string $birthDate, string $snils, array $extra = []): array
    {
        return array_merge([
            'application_number' => '1001',
            'status' => 'В приказе',
            'fio' => $fio,
            'order_name' => 'О зачислении абитуриентов в число студентов',
            'order_number' => '',
            'passport' => '',
            'passport_issuer' => '',
            'passport_issued_at' => '',
            'passport_department_code' => '',
            'citizenship' => '',
            'gender' => '',
            'birth_date' => $birthDate,
            'place_birth' => '',
            'region' => '',
            'settlement_type' => '',
            'address' => '',
            'snils' => $snils,
            'email' => '',
        ], $extra);
    }

    /** @param list<array<string, string>> $rows */
    private function makeExport(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach (self::HEADERS as $index => $header) {
            $sheet->setCellValue([$index + 1, 1], $header);
        }

        $order = array_keys($this->row('', '', ''));
        foreach ($rows as $rowIndex => $row) {
            foreach ($order as $columnIndex => $field) {
                $sheet->setCellValueExplicit(
                    [$columnIndex + 1, $rowIndex + 2],
                    $row[$field] ?? '',
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING,
                );
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'fis').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    private function passportDocumentType(): ReferenceItem
    {
        $catalog = ReferenceCatalog::firstOrCreate(
            ['code' => 'admission_identity_document_types'],
            ['name' => 'Виды документов, удостоверяющих личность'],
        );

        return ReferenceItem::firstOrCreate(
            ['catalog_id' => $catalog->id, 'code' => 'russian_passport'],
            ['name' => 'Паспорт гражданина Российской Федерации'],
        );
    }
}
