<?php

namespace Tests\Feature;

use App\Models\EducationProgram;
use App\Models\Group;
use App\Models\Person;
use App\Models\Specialty;
use App\Models\Student;
use App\Models\StudentCertificate;
use App\Services\Students\StudentCertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Перенос бумажного реестра справок.
 *
 * Данные вымышленные. Проверяется главное: бумажная строка **не притворяется**
 * выданной порталом. В реестре колледжа нет ни даты выдачи, ни курса, ни сроков
 * обучения, а у 89 строк из 591 нет и студента — заполнить это из сегодняшней
 * карточки значило бы соврать о том, что стояло на бумаге.
 */
class CertificateRegisterImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_paper_row_arrives_with_what_the_register_had_and_nothing_more(): void
    {
        $this->makeStudent('Астахова', 'Вероника', 'Игоревна', '2008-05-14');

        $this->import([
            ['1', 'Астахова Вероника Игоревна', '14.05.2008', 'Хореографическое творчество', '114', '17.08.2026', '729', '730'],
        ]);

        $this->assertSame(2, StudentCertificate::count());

        $row = StudentCertificate::where('number', 729)->firstOrFail();

        $this->assertSame(StudentCertificate::SOURCE_PAPER, $row->source);
        $this->assertSame('Астахова Вероника Игоревна', $row->full_name);
        $this->assertSame('114', $row->enrollment_order_number);
        $this->assertSame('2026-08-17', $row->enrollment_order_date->toDateString());

        // Чего в реестре нет, того нет и в строке.
        $this->assertNull($row->issued_on);
        $this->assertNull($row->course);
        $this->assertNull($row->study_form);
        $this->assertNull($row->study_start);
    }

    public function test_a_row_finds_its_student_by_name_and_birth_date(): void
    {
        $student = $this->makeStudent('Белозёров', 'Тимофей', 'Русланович', '2007-03-02');

        $this->import([
            ['1', 'Белозёров Тимофей Русланович', '02.03.2007', 'Театральное творчество', '91', '15.08.2025', '731', '732'],
        ]);

        $this->assertSame($student->id, StudentCertificate::where('number', 731)->firstOrFail()->student_id);
    }

    public function test_a_row_whose_student_is_gone_still_arrives_without_one(): void
    {
        // 89 строк из 591 не за кем закрепить: это выбывшие, а не сбой сверки.
        // Потерять их значит потерять и номера, а нумерация обязана быть сплошной.
        $this->import([
            ['1', 'Выбывшая Полина Олеговна', '11.11.2006', 'Фортепиано', '106', '18.08.2023', '733', '734'],
        ]);

        $row = StudentCertificate::where('number', 733)->firstOrFail();

        $this->assertNull($row->student_id);
        $this->assertSame('Выбывшая Полина Олеговна', $row->full_name);
    }

    public function test_the_letter_yo_does_not_hide_a_student(): void
    {
        $student = $this->makeStudent('Артёмова', 'Алёна', 'Семёновна', '2006-09-09');

        $this->import([
            ['1', 'Артемова Алена Семеновна', '09.09.2006', 'Теория музыки', '122', '16.08.2024', '741', '742'],
        ]);

        $this->assertSame($student->id, StudentCertificate::where('number', 741)->firstOrFail()->student_id);
    }

    public function test_loading_twice_adds_nothing_the_second_time(): void
    {
        $rows = [['1', 'Ветрова Мирослава Олеговна', '01.02.2007', 'Вокальное искусство', '114', '17.08.2026', '751', '752']];

        $this->import($rows);
        $this->import($rows);

        $this->assertSame(2, StudentCertificate::count());
    }

    public function test_a_number_already_issued_by_the_portal_stops_the_whole_file(): void
    {
        // Один номер за двумя документами разбирает человек, а не загрузчик.
        $student = $this->makeStudent('Гордеев', 'Демид', 'Артёмович', '2005-01-20');
        $issued = app(StudentCertificateService::class)->issue($student, copies: 1)->first();

        $code = $this->import([
            ['1', 'Кто-то Другой Иванович', '03.03.2003', 'Фортепиано', '114', '17.08.2026', (string) $issued->number, ''],
            ['2', 'И Ещё Один Петрович', '04.04.2004', 'Фортепиано', '114', '17.08.2026', '801', '802'],
        ]);

        $this->assertSame(1, $code, 'Загрузка обязана отказать целиком.');
        // Вторая строка тоже не проехала: отказ на весь файл, а не на строку.
        $this->assertSame(0, StudentCertificate::where('number', 801)->count());
    }

    public function test_the_portal_keeps_numbering_after_the_paper_ones(): void
    {
        // Ради этого загрузка ценна и сама по себе: после неё счёт опирается на
        // базу, а не только на настройку, которую можно понизить по ошибке.
        $this->import([
            ['1', 'Дьяконова Ксения Павловна', '07.07.2007', 'Фортепиано', '114', '17.08.2026', '1908', '1909'],
        ]);

        $student = $this->makeStudent('Ерофеева', 'Ефросинья', 'Всеволодовна', '2008-08-08');
        $issued = app(StudentCertificateService::class)->issue($student);

        $this->assertSame([1910, 1911], $issued->pluck('number')->all());
    }

    public function test_the_register_finds_a_paper_row_by_its_number(): void
    {
        // То, ради чего владелец просил реестр: найти по номеру, кому выдавалась.
        $this->import([
            ['1', 'Жбанов Пётр Ильич', '12.12.2006', 'Фортепиано', '106', '18.08.2023', '900', '901'],
        ]);

        $found = app(StudentCertificateService::class)->registry(number: 900);

        $this->assertCount(1, $found);
        $this->assertSame('Жбанов Пётр Ильич', $found->first()->full_name);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->import(
            [['1', 'Холостая Проба Ивановна', '05.05.2005', 'Фортепиано', '114', '17.08.2026', '950', '951']],
            dryRun: true,
        );

        $this->assertSame(0, StudentCertificate::count());
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function import(array $rows, bool $dryRun = false): int
    {
        $path = tempnam(sys_get_temp_dir(), 'register').'.csv';
        $handle = fopen($path, 'w');

        fputcsv($handle, ['№', 'ФИО студента', 'Дата рождения', 'Специальность', 'Приказ', 'Дата приказа', 'Справка 1', 'Справка 2']);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        $code = $this->artisan('certificates:import-register', array_filter([
            'file' => $path,
            '--dry-run' => $dryRun ?: null,
        ]))->run();

        unlink($path);

        return $code;
    }

    private function makeStudent(string $last, string $first, string $middle, string $birthDate): Student
    {
        // Группе нужна образовательная программа: из неё берутся форма обучения
        // и срок, без них служба справку выписывать отказывается — и правильно
        // делает. Первая заготовка этого теста программу не завела, и два
        // теста упали не по своей вине.
        $specialty = Specialty::firstOrCreate(['code' => '51.02.01'], ['name' => 'Народное художественное творчество']);

        $program = EducationProgram::firstOrCreate(
            ['name' => 'Хореографическое творчество'],
            [
                'specialty_id' => $specialty->id,
                'year_start' => 2026,
                'study_form' => 'Очная',
                'study_years' => 3.8,
                'is_active' => true,
            ],
        );

        $group = Group::firstOrCreate(
            ['name' => 'Хореографическое творчество, набор 2026'],
            [
                'specialty' => 'Народное художественное творчество',
                'education_program_id' => $program->id,
                'year_start' => 2026,
            ],
        );

        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'last_name' => $last, 'first_name' => $first, 'middle_name' => $middle,
            'birth_date' => $birthDate, 'status' => 'active',
        ]);

        return Student::create([
            'person_id' => $person->id,
            'group_id' => $group->id,
            'course' => 1,
            'last_name' => $last, 'first_name' => $first, 'middle_name' => $middle,
            'birth_date' => $birthDate,
            'status' => 'active',
            'enrollment_order_number' => '124',
            'enrollment_order_date' => '2026-08-28',
        ]);
    }
}
