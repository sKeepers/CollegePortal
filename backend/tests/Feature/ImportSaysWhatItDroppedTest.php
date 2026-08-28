<?php

namespace Tests\Feature;

use App\Models\Admissions\EducationDocument;
use App\Models\EducationProgram;
use App\Models\Group;
use App\Models\Specialty;
use App\Models\Subject;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Замечание к строке, которая загрузилась.
 *
 * У строки было только три исхода — создана, обновлена, пропущена, — и места под
 * «загрузилась, но кое-что потерялось» не было вовсе. Из-за этого 22.08.2026 исчезли
 * 580 названий школ, а опечатка в фамилии преподавателя роняла целую дисциплину.
 *
 * Отказывать всей строкой в таких случаях нельзя: студента не загрузить из-за школы, а
 * дисциплину — из-за буквы в чужой фамилии. Молчать нельзя тем более: владелец загрузит
 * файлы один раз за считаные дни до первого сентября, и о потерянном узнает первого.
 *
 * Здесь закреплено и то и другое разом: **строка в портале**, и **замечание названо**.
 */
class ImportSaysWhatItDroppedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withApiAuth();
    }

    /**
     * Опечатка в фамилии преподавателя больше не роняет дисциплину.
     *
     * Раньше это была ошибка строки: буква в чужой фамилии — и дисциплины в портале нет
     * вовсе, а первого сентября её не на что поставить в расписание.
     */
    public function test_an_unknown_teacher_does_not_stop_the_subject(): void
    {
        Teacher::create(['last_name' => 'Петрова', 'first_name' => 'Анна', 'middle_name' => 'Викторовна', 'is_active' => true]);

        $result = $this->import('subjects', "Дисциплина;Код;Преподаватели\nСольфеджио;SOLF;Петрова Анна Виктровна\n", [
            'name' => 'Дисциплина', 'code' => 'Код', 'teachers' => 'Преподаватели',
        ]);

        $this->assertSame(1, $result['created_count'], 'дисциплина обязана загрузиться');
        $this->assertSame(0, $result['error_count'], 'это не ошибка строки');
        $this->assertDatabaseHas('subjects', ['code' => 'SOLF']);

        $this->assertCount(1, $result['warnings']);
        $this->assertSame(2, $result['warnings'][0]['row']);
        $this->assertStringContainsString('Виктровна', $result['warnings'][0]['reason']);
    }

    /** Преподаватель нашёлся — замечаний нет и быть не должно. */
    public function test_a_known_teacher_produces_no_notice(): void
    {
        Teacher::create(['last_name' => 'Петрова', 'first_name' => 'Анна', 'middle_name' => 'Викторовна', 'is_active' => true]);

        $result = $this->import('subjects', "Дисциплина;Код;Преподаватели\nСольфеджио;SOLF;Петрова Анна Викторовна\n", [
            'name' => 'Дисциплина', 'code' => 'Код', 'teachers' => 'Преподаватели',
        ]);

        $this->assertSame(1, $result['created_count']);
        $this->assertSame([], $result['warnings']);
    }

    /**
     * Программа и куратор названы, но не нашлись: группа загружается без них, и об этом
     * сказано. Раньше опечатка в фамилии куратора ничем не отличалась от пустой клетки.
     */
    public function test_an_unknown_programme_and_curator_are_named(): void
    {
        $result = $this->import('groups', "Группа;Специальность;Год набора;Образовательная программа;Куратор\nФортепиано, набор 2026;Инструментальное исполнительство;2026;Фортепиано;Кузнецов Игорь Павлович\n", [
            'name' => 'Группа', 'specialty' => 'Специальность', 'year_start' => 'Год набора',
            'education_program_name' => 'Образовательная программа', 'curator_name' => 'Куратор',
        ]);

        $this->assertSame(1, $result['created_count']);
        $this->assertSame(0, $result['error_count']);
        $this->assertDatabaseHas('groups', ['name' => 'Фортепиано, набор 2026', 'education_program_id' => null, 'curator_id' => null]);

        $reasons = implode(' ', array_column($result['warnings'], 'reason'));
        $this->assertStringContainsString('Фортепиано', $reasons);
        $this->assertStringContainsString('Кузнецов Игорь Павлович', $reasons);
    }

    /** Программа и куратор на месте — молчание. */
    public function test_a_resolved_programme_and_curator_produce_no_notice(): void
    {
        $specialty = Specialty::create(['code' => '53.02.03', 'name' => 'Инструментальное исполнительство']);
        EducationProgram::create(['specialty_id' => $specialty->id, 'name' => 'Фортепиано', 'year_start' => 2026, 'study_form' => 'full_time', 'is_active' => true]);
        Teacher::create(['last_name' => 'Кузнецов', 'first_name' => 'Игорь', 'middle_name' => 'Павлович', 'is_active' => true]);

        $result = $this->import('groups', "Группа;Специальность;Год набора;Образовательная программа;Куратор\nФортепиано, набор 2026;Инструментальное исполнительство;2026;Фортепиано;Кузнецов Игорь Павлович\n", [
            'name' => 'Группа', 'specialty' => 'Специальность', 'year_start' => 'Год набора',
            'education_program_name' => 'Образовательная программа', 'curator_name' => 'Куратор',
        ]);

        $this->assertSame(1, $result['created_count']);
        $this->assertSame([], $result['warnings']);
    }

    /**
     * Школа названа, аттестата нет — тот самый случай, на котором исчезли 580 названий.
     *
     * Документ по-прежнему не заводится: создавать аттестат из одного названия школы
     * владелец не разрешал. Но теперь об этом сказано.
     */
    public function test_a_school_without_a_certificate_is_named(): void
    {
        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'year_start' => 2026]);

        $result = $this->import('students', "Фамилия;Имя;Группа;Учебное заведение;Год окончания\nИванова;Мария;{$group->name};МБОУ СОШ № 7 г. Ставрополя;2026\n", [
            'last_name' => 'Фамилия', 'first_name' => 'Имя', 'group_name' => 'Группа',
            'education_document_organization' => 'Учебное заведение', 'education_graduation_year' => 'Год окончания',
        ]);

        $this->assertSame(1, $result['created_count'], 'студент обязан загрузиться: у школы нет номера аттестата, а у человека есть имя');
        $this->assertSame(0, $result['error_count']);
        $this->assertSame(0, EducationDocument::query()->count());

        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('Документ об образовании не сохранён', $result['warnings'][0]['reason']);
    }

    /** Аттестат с реквизитами сохраняется, и замечания нет. */
    public function test_a_certificate_with_requisites_is_silent(): void
    {
        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'year_start' => 2026]);

        $result = $this->import('students', "Фамилия;Имя;Группа;Учебное заведение;Серия документа об образовании;Номер документа об образовании\nПетров;Илья;{$group->name};МБОУ СОШ № 3;АБ;123456\n", [
            'last_name' => 'Фамилия', 'first_name' => 'Имя', 'group_name' => 'Группа',
            'education_document_organization' => 'Учебное заведение',
            'education_document_series' => 'Серия документа об образовании',
            'education_document_number' => 'Номер документа об образовании',
        ]);

        $this->assertSame(1, $result['created_count']);
        $this->assertSame([], $result['warnings']);
        $this->assertSame(1, EducationDocument::query()->count());
    }

    /**
     * Замечание видно **до** подтверждения.
     *
     * В этом весь смысл: файл ещё можно поправить. После загрузки останется только
     * догадываться, чего в портале нет и почему.
     */
    public function test_notices_are_visible_before_confirming(): void
    {
        Teacher::create(['last_name' => 'Петрова', 'first_name' => 'Анна', 'middle_name' => 'Викторовна', 'is_active' => true]);
        $mapping = ['name' => 'Дисциплина', 'code' => 'Код', 'teachers' => 'Преподаватели'];
        $jobId = $this->preview('subjects', "Дисциплина;Код;Преподаватели\nСольфеджио;SOLF;Петрова Анна Виктровна\n");

        $data = $this->postJson("/api/admin/import/{$jobId}/validate", ['mode' => 'create', 'mapping' => $mapping])
            ->assertOk()
            ->json('data');

        $this->assertSame('validated', $data['status'], 'замечание не делает файл негодным');
        $this->assertSame(0, $data['error_count']);
        $this->assertCount(1, $data['warnings']);
        // Проверка ничего не загружает: файл ещё можно поправить.
        $this->assertDatabaseCount('subjects', 0);
    }

    /** Загрузчику без ссылок нечего терять, и он молчит. */
    public function test_a_handler_with_nothing_to_lose_says_nothing(): void
    {
        $result = $this->import('classrooms', "Аудитория;Корпус;Вместимость\n201;Главный корпус;24\n", [
            'number' => 'Аудитория', 'building' => 'Корпус', 'capacity' => 'Вместимость',
        ]);

        $this->assertSame(1, $result['created_count']);
        $this->assertSame([], $result['warnings']);
    }

    /** @param array<string, string> $mapping @return array<string, mixed> */
    private function import(string $type, string $csv, array $mapping): array
    {
        $jobId = $this->preview($type, $csv);

        return $this->postJson("/api/admin/import/{$jobId}/confirm", ['mode' => 'create', 'mapping' => $mapping])
            ->assertOk()
            ->json('data');
    }

    private function preview(string $type, string $csv): int
    {
        $path = storage_path('framework/testing/import-notices.csv');
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $csv);

        return (int) $this->post('/api/admin/import/preview', [
            'data_type' => $type,
            'file' => new UploadedFile($path, 'import-notices.csv', 'text/csv', null, true),
        ])->assertCreated()->json('data.id');
    }
}
