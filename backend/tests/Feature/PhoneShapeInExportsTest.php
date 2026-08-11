<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Teacher;
use App\Services\TeacherCsvService;
use App\Support\Phone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Телефон в выгрузках пишется одним видом.
 *
 * Владелец сверял файл с тем, что вводил, и видел расхождение: одни номера
 * приходили с плюсом, другие без. Причина не в выгрузке: общие данные человека
 * пишет только `PersonService`, и он оставляет от номера одни цифры, поэтому
 * вид зависел от того, правили ли карточку через портал. Выгрузка показывала
 * это как попало — в одном файле два написания сразу.
 */
class PhoneShapeInExportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withApiAuth();
    }

    public function test_export_writes_one_shape_for_both_stored_forms(): void
    {
        $this->seedTeacher('Петрова', 'Анна', '+79990000010', 'plus@example.test');
        $this->seedTeacher('Смирнова', 'Елена', '79990000011', 'digits@example.test');

        $lines = $this->exportLines();

        $this->assertStringContainsString('+7 999 000 0010', $lines[1]);
        $this->assertStringContainsString('+7 999 000 0011', $lines[2]);
    }

    /**
     * Круг устойчив: обратная загрузка снимает пробелы и плюс, как снимала
     * раньше, поэтому вторая выгрузка даёт тот же файл до символа. Без этого
     * «единый вид» превратился бы в бесконечно меняющийся файл.
     */
    public function test_second_export_after_a_round_trip_is_identical(): void
    {
        $this->seedTeacher('Петрова', 'Анна', '+79990000010', 'plus@example.test');

        $first = implode("\n", $this->exportLines());
        $this->importCsv($first);
        $second = implode("\n", $this->exportLines());

        $this->assertSame($first, $second, 'Файл обязан совпадать после круга');
        $this->assertSame('79990000010', Teacher::query()->firstOrFail()->person?->phone, 'Хранение не меняется: цифры как были');
    }

    /** Чужие написания не трогаются: править вслепую значит искажать данные. */
    public function test_foreign_and_short_numbers_are_left_alone(): void
    {
        $this->assertSame('+7 962 448 7133', Phone::forExport('79624487133'));
        $this->assertSame('+7 962 448 7133', Phone::forExport('8 (962) 448-71-33'));
        $this->assertSame('+49 30 123456', Phone::forExport('+49 30 123456'));
        $this->assertSame('2-14', Phone::forExport('2-14'));
        $this->assertNull(Phone::forExport(null));
    }

    private function seedTeacher(string $lastName, string $firstName, string $phone, string $email): void
    {
        $person = Person::create([
            'last_name' => $lastName,
            'first_name' => $firstName,
            'phone' => $phone,
            'email' => $email,
            'status' => 'active',
        ]);

        Teacher::create([
            'person_id' => $person->id,
            'last_name' => $lastName,
            'first_name' => $firstName,
            'phone' => $phone,
            'email' => $email,
            'position' => 'Преподаватель',
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

    private function importCsv(string $csv): void
    {
        $path = tempnam(sys_get_temp_dir(), 'teachers').'.csv';
        file_put_contents($path, $csv);

        app(TeacherCsvService::class)->import(new UploadedFile($path, 'teachers.csv', 'text/csv', null, true));
    }
}
