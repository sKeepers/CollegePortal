<?php

namespace Tests\Feature;

use App\Models\EducationProgram;
use App\Models\Group;
use App\Models\Specialty;
use App\Models\Teacher;
use App\Services\Import\GroupImportHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Выгрузка групп и обратная загрузка того же файла.
 *
 * Файл отдавал и образовательную программу, и куратора, а обратная загрузка
 * теряла обе: программу — потому что заголовок был машинным именем, куратора —
 * потому что такого поля в шаблоне не было вовсе.
 */
class GroupExportRoundTripTest extends TestCase
{
    use RefreshDatabase;

    public function test_program_and_curator_survive_the_round_trip(): void
    {
        $handler = app(GroupImportHandler::class);
        $specialty = Specialty::create(['code' => '53.02.03', 'name' => 'Инструментальное исполнительство']);
        $program = EducationProgram::create([
            'specialty_id' => $specialty->id,
            'name' => 'Фортепиано',
            'year_start' => 2026,
            'study_form' => 'Очная',
            'is_active' => true,
        ]);
        $curator = Teacher::create(['last_name' => 'Петрова', 'first_name' => 'Анна', 'middle_name' => 'Викторовна', 'is_active' => true]);

        // Ровно те заголовки, которые отдаёт выгрузка групп, разобранные так же,
        // как их разбирает импорт: сначала заголовок сводится к полю по
        // псевдонимам, и только потом идёт prepare.
        $row = $handler->prepare($this->mapHeaders($handler, [
            'name' => 'ИСП-101',
            'specialty' => $specialty->name,
            'course' => '1',
            'year_start' => '2026',
            'education_program' => $program->name,
            'curator' => 'Петрова Анна Викторовна',
        ]));

        $this->assertSame($program->id, $row['education_program_id'], 'Программа должна опознаваться по колонке выгрузки');
        $this->assertSame($curator->id, $row['curator_id'], 'Куратор должен опознаваться по колонке выгрузки');

        $handler->import($row, GroupImportHandler::MODE_SKIP_DUPLICATES);

        $group = Group::query()->firstOrFail();
        $this->assertSame($program->id, $group->education_program_id);
        $this->assertSame($curator->id, $group->curator_id);
    }

    public function test_curator_column_is_part_of_the_template(): void
    {
        $this->assertContains('Куратор', app(GroupImportHandler::class)->templateHeaders());
    }

    /**
     * Заголовок файла в поле обработчика — по ключу, подписи или псевдониму.
     * Тем же правилом их сводит «Универсальный импорт», когда предлагает
     * сопоставление колонок.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapHeaders(GroupImportHandler $handler, array $row): array
    {
        $mapped = [];

        foreach ($row as $header => $value) {
            $needle = mb_strtolower($header);
            $field = $header;

            foreach ($handler->fields() as $key => $definition) {
                $known = array_map('mb_strtolower', [$key, $definition['label'], ...($definition['aliases'] ?? [])]);

                if (in_array($needle, $known, true)) {
                    $field = $key;
                    break;
                }
            }

            $mapped[$field] = $value;
        }

        return $mapped;
    }
}
