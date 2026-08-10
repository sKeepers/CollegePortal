<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Teacher;
use App\Services\Import\TeacherImportHandler;
use App\Services\TeacherCsvService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Выгрузка преподавателей и обратная загрузка того же файла.
 *
 * Шаблон преподавателя был самым бедным из всех: девять колонок, ни даты
 * рождения, ни СНИЛС, ни адреса, а выгрузка отдавала машинные имена полей.
 * Владелец использует файл как заготовку — выгрузил, дополнил в Excel,
 * загрузил обратно, — и терять при этом половину карточки нельзя.
 */
class TeacherExportRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withApiAuth();
    }

    public function test_export_columns_match_the_import_template(): void
    {
        $this->seedTeacher();

        $lines = $this->exportLines();
        $headers = array_map(fn (string $value): string => trim($value, "\u{FEFF}\" \r"), explode(';', $lines[0]));

        $this->assertSame(app(TeacherImportHandler::class)->templateHeaders(), $headers);
    }

    public function test_exported_card_survives_the_round_trip(): void
    {
        $this->seedTeacher();
        $csv = implode("\n", $this->exportLines());

        // Карточка стирается целиком: если обратная загрузка что-то теряет,
        // это станет видно, а не спрячется за уже существующими данными.
        Teacher::query()->delete();
        Person::query()->delete();

        $summary = $this->importCsv($csv);

        $this->assertSame([], $summary['errors']);
        $this->assertSame(1, $summary['created']);

        $teacher = Teacher::query()->firstOrFail();
        $this->assertSame('Петрова', $teacher->last_name);
        $this->assertSame('Анна', $teacher->first_name);
        $this->assertSame('Викторовна', $teacher->middle_name);
        $this->assertSame('Преподаватель', $teacher->position);
        $this->assertSame('Музыкальное отделение', $teacher->department);
        $this->assertTrue($teacher->is_active);

        // Дата рождения, СНИЛС и адрес живут у человека: своих колонок
        // у преподавателя для них нет.
        $person = $teacher->person;
        $this->assertNotNull($person, 'Преподаватель обязан быть связан с человеком');
        $this->assertSame('1985-04-15', $person->birth_date?->toDateString());
        $this->assertSame('г. Ставрополь, ул. Мира, 1', $person->address);
        $this->assertSame('12345678964', preg_replace('/\D+/', '', (string) $person->snils), 'СНИЛС должен пережить обратную загрузку');
        // Цифры, а не написание: PersonService приводит телефон к своему виду,
        // и плюс он снимает. Номер при этом не теряется, а формат — его правило.
        $this->assertSame('79990000010', preg_replace('/\D+/', '', (string) $person->phone));
    }

    /**
     * Столбец «Создать учетную запись» выгружается пустым: обратная загрузка
     * не должна переоформлять учётные записи.
     */
    public function test_round_trip_does_not_provision_accounts(): void
    {
        $this->seedTeacher();
        $csv = implode("\n", $this->exportLines());
        $values = explode(';', $this->exportLines()[1]);

        $this->assertSame('', trim(end($values), "\" \r"), 'Последняя колонка — «Создать учетную запись» — должна быть пустой');

        Teacher::query()->delete();
        Person::query()->delete();
        $this->importCsv($csv);

        $this->assertNull(Teacher::query()->firstOrFail()->user_id);
    }

    /** Файлы по прежнему образцу с машинными именами колонок грузятся по-прежнему. */
    public function test_legacy_headers_are_still_accepted(): void
    {
        $csv = "last_name;first_name;middle_name;phone;email;position;department;is_active\n"
            ."Смирнова;Елена;Викторовна;+79990000011;legacy@example.test;Преподаватель;Общеобразовательное отделение;1\n";

        $summary = $this->importCsv($csv);

        $this->assertSame([], $summary['errors']);
        $this->assertSame(1, $summary['created']);
        $this->assertSame('Смирнова', Teacher::query()->firstOrFail()->last_name);
    }

    private function seedTeacher(): void
    {
        $person = Person::create([
            'last_name' => 'Петрова',
            'first_name' => 'Анна',
            'middle_name' => 'Викторовна',
            'birth_date' => '1985-04-15',
            'phone' => '+79990000010',
            'email' => 'teacher.export@example.test',
            'address' => 'г. Ставрополь, ул. Мира, 1',
            'snils' => '123-456-789 64',
            'status' => 'active',
        ]);

        Teacher::create([
            'person_id' => $person->id,
            'last_name' => 'Петрова',
            'first_name' => 'Анна',
            'middle_name' => 'Викторовна',
            'phone' => '+79990000010',
            'email' => 'teacher.export@example.test',
            'position' => 'Преподаватель',
            'department' => 'Музыкальное отделение',
            'is_active' => true,
        ]);
    }

    /** @return list<string> */
    private function exportLines(): array
    {
        $response = $this->get('/api/teachers/export');
        $response->assertOk();

        return array_values(array_filter(explode("\n", trim($response->streamedContent()))));
    }

    /** @return array<string, mixed> */
    private function importCsv(string $csv): array
    {
        $path = tempnam(sys_get_temp_dir(), 'teachers').'.csv';
        file_put_contents($path, $csv);

        return app(TeacherCsvService::class)->import(new UploadedFile($path, 'teachers.csv', 'text/csv', null, true));
    }
}
