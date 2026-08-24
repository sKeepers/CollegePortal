<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Services\Admissions\EducationDocumentService;
use App\Services\StudentCsvService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Строка, где названа школа, но нет серии и номера аттестата.
 *
 * Данные вымышленные, случай настоящий. 22.08.2026 контингент заводили из
 * списка учебной части, где графа «Учебное заведение» заполнена у **580 строк
 * из 593**, а колонок серии и номера аттестата нет вовсе. Все 580 названий
 * прошли через загрузку и исчезли: служба возвращала `null`, загрузчик
 * возвращённое не смотрел. На стенде осталось ноль документов об образовании, и
 * полтора месяца этот ноль объясняли тем, что данных нет ни в одном источнике.
 *
 * Здесь закреплено не создание документа — создавать его из одного названия
 * школы владелец ещё не разрешил, — а **то, что загрузка об этом говорит**.
 */
class EducationDocumentIsNotDroppedSilentlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_row_with_only_a_school_is_counted_and_named(): void
    {
        $summary = $this->import([
            ['Иванова', 'Мария', 'Сергеевна', 'МБОУ СОШ № 7 г. Ставрополя', '', ''],
        ]);

        $this->assertSame(1, $summary['created'], 'студент обязан загрузиться: у школы нет номера аттестата, а у человека есть имя');
        $this->assertSame([], $summary['errors'], 'это не ошибка строки — отказывать целиком нельзя');
        $this->assertSame([2], $summary['education_documents_skipped'], 'строка обязана попасть в отчёт с номером');
    }

    public function test_a_row_with_requisites_creates_the_document_and_says_nothing(): void
    {
        $summary = $this->import([
            ['Петров', 'Илья', 'Олегович', 'МБОУ СОШ № 3 г. Ставрополя', 'АБ', '123456'],
        ]);

        $this->assertSame(1, $summary['created']);
        $this->assertSame([], $summary['education_documents_skipped'], 'документ создан, сообщать не о чем');
    }

    public function test_a_row_without_a_school_at_all_is_not_counted(): void
    {
        // Пустая графа — это «не заполняли», а не «потеряли». Считать такие
        // строки значит утопить настоящую находку в шуме: их сотни.
        $summary = $this->import([
            ['Сидорова', 'Анна', 'Ивановна', '', '', ''],
        ]);

        $this->assertSame([], $summary['education_documents_skipped']);
    }

    public function test_the_counter_holds_every_line_not_just_the_first(): void
    {
        $summary = $this->import([
            ['Иванова', 'Мария', 'Сергеевна', 'МБОУ СОШ № 7', '', ''],
            ['Петров', 'Илья', 'Олегович', 'МБОУ СОШ № 3', 'АБ', '123456'],
            ['Сидорова', 'Анна', 'Ивановна', 'МБОУ СОШ № 1', '', ''],
        ]);

        $this->assertSame([2, 4], $summary['education_documents_skipped']);
        $this->assertSame(3, $summary['created']);
    }

    /** Правило само по себе: оно решает, что считать потерей. */
    public function test_the_rule_names_only_the_school_only_case(): void
    {
        $only = ['document_organization' => 'МБОУ СОШ № 7', 'series' => '', 'number' => ''];
        $withSeries = ['document_organization' => 'МБОУ СОШ № 7', 'series' => 'АБ', 'number' => ''];
        $withNumber = ['document_organization' => 'МБОУ СОШ № 7', 'series' => '', 'number' => '123456'];
        $nothing = ['document_organization' => '', 'series' => '', 'number' => ''];

        $this->assertTrue(EducationDocumentService::isOrganisationOnly($only));
        $this->assertFalse(EducationDocumentService::isOrganisationOnly($withSeries));
        $this->assertFalse(EducationDocumentService::isOrganisationOnly($withNumber));
        $this->assertFalse(EducationDocumentService::isOrganisationOnly($nothing));
    }

    /**
     * @param  list<list<string>>  $rows
     * @return array<string, mixed>
     */
    private function import(array $rows): array
    {
        Group::firstOrCreate(
            ['name' => 'Хореографическое творчество, набор 2026'],
            ['specialty' => 'Народное художественное творчество', 'year_start' => 2026],
        );

        $lines = ['Фамилия;Имя;Отчество;Учебное заведение;Серия документа об образовании;Номер документа об образовании'];

        foreach ($rows as $row) {
            $lines[] = implode(';', $row);
        }

        $path = tempnam(sys_get_temp_dir(), 'students').'.csv';
        file_put_contents($path, implode("\n", $lines));

        return app(StudentCsvService::class)->import(
            new UploadedFile($path, 'students.csv', 'text/csv', null, true),
        );
    }
}
