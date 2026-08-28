<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Teacher;
use App\Services\PersonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Преподаватель без email находится по человеку, а не заводится заново.
 *
 * Ключ обновления у преподавателей объявлен один — email. Пока файлы собирали руками,
 * он в них был; выгрузка кадров, принесённая владельцем 28.08.2026, email не содержит
 * вовсе — ни у одного из 175 человек. С единственным ключом такая строка не находила
 * никого, и **каждая загрузка заводила преподавателя заново**: вторая загрузка того же
 * файла удвоила бы список, третья утроила.
 *
 * Хуже удвоения было второе следствие. Человек искался только по СНИЛС, которого в
 * выгрузке тоже нет, поэтому каждому преподавателю заводилась **новая карточка
 * человека** — включая тех десятерых, что уже есть в портале сотрудниками. Один живой
 * человек получил бы две карточки, и документы, пропуск и вход остались бы у первой.
 *
 * Здесь закреплено и то и другое, и отдельно — отказ угадывать между полными тёзками.
 */
class TeacherImportKeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withApiAuth();
    }

    /** Тот же файл, загруженный второй раз, обновляет карточку, а не заводит вторую. */
    public function test_a_second_load_of_the_same_file_does_not_double_the_teacher(): void
    {
        $csv = "Фамилия;Имя;Отчество\nАбрамов;Вадим;Петрович\n";
        $mapping = ['last_name' => 'Фамилия', 'first_name' => 'Имя', 'middle_name' => 'Отчество'];

        $first = $this->import('teachers', $csv, $mapping, 'create');
        $this->assertSame(1, $first['created_count'], 'первая загрузка заводит преподавателя');

        $second = $this->import('teachers', $csv, $mapping, 'update');
        $this->assertSame(1, $second['updated_count'], 'вторая загрузка обязана обновить, а не пропустить');

        $this->assertSame(1, Teacher::count(), 'преподаватель в портале один');
        $this->assertSame(1, Person::count(), 'и карточка человека у него одна');
    }

    /** Преподаватель встаёт к человеку, который уже есть в портале, а не рядом с ним. */
    public function test_a_teacher_joins_the_person_already_in_the_portal(): void
    {
        $person = app(PersonService::class)->createPerson([
            'last_name' => 'Горбачева', 'first_name' => 'Татьяна', 'middle_name' => 'Владимировна',
        ]);

        $result = $this->import('teachers', "Фамилия;Имя;Отчество\nГорбачева;Татьяна;Владимировна\n", [
            'last_name' => 'Фамилия', 'first_name' => 'Имя', 'middle_name' => 'Отчество',
        ], 'create');

        $this->assertSame(1, $result['created_count']);
        $this->assertSame(1, Person::count(), 'вторая карточка человека не заводится');
        $this->assertSame((int) $person->id, (int) Teacher::first()->person_id);
    }

    /** Двух тёзок строка без email не различает — и портал отказывается угадывать. */
    public function test_two_namesakes_stop_the_row_instead_of_guessing(): void
    {
        $people = app(PersonService::class);
        $people->createPerson(['last_name' => 'Маршанский', 'first_name' => 'Станислав', 'middle_name' => 'Анатольевич']);
        $people->createPerson(['last_name' => 'Маршанский', 'first_name' => 'Станислав', 'middle_name' => 'Анатольевич']);

        $result = $this->import('teachers', "Фамилия;Имя;Отчество\nМаршанский;Станислав;Анатольевич\n", [
            'last_name' => 'Фамилия', 'first_name' => 'Имя', 'middle_name' => 'Отчество',
        ], 'create');

        $this->assertSame(0, $result['created_count'], 'угадывать между тёзками нельзя');
        $this->assertSame(1, $result['error_count'], 'и молчать тоже нельзя');
        $this->assertSame(0, Teacher::count());
        $this->assertStringContainsString('несколько человек', $result['validation_errors'][0]['reason']);
    }


    /**
     * Число вместо отчества останавливает строку, а не заводит человека.
     *
     * Так выгрузка CARDDEX помечает, что карт у человека несколько: «1», «2»,
     * «3» вместо отчества. 28.08.2026 загрузка приняла пометку за отчество и
     * завела семь карточек вместо трёх — три Михайловых Дмитрия, две Сидоренко
     * Алины, две Трубач Екатерины. Разбирали слиянием, задним числом.
     */
    public function test_a_number_instead_of_a_name_stops_the_row(): void
    {
        $result = $this->import('teachers', "Фамилия;Имя;Отчество\nМихайлов;Дмитрий;1\n", [
            'last_name' => 'Фамилия', 'first_name' => 'Имя', 'middle_name' => 'Отчество',
        ], 'create');

        $this->assertSame(0, $result['created_count'], 'пометку выгрузки нельзя принять за человека');
        $this->assertSame(1, $result['error_count']);
        $this->assertSame(0, Teacher::count());
        $this->assertStringContainsString('числом', $result['validation_errors'][0]['reason']);
    }

    /** Отчество из букв по-прежнему грузится: правило не должно ловить живых людей. */
    public function test_a_real_patronymic_still_loads(): void
    {
        $result = $this->import('teachers', "Фамилия;Имя;Отчество\nМихайлов;Дмитрий;Петрович\n", [
            'last_name' => 'Фамилия', 'first_name' => 'Имя', 'middle_name' => 'Отчество',
        ], 'create');

        $this->assertSame(1, $result['created_count']);
        $this->assertSame(0, $result['error_count']);
    }

    private function import(string $type, string $csv, array $mapping, string $mode): array
    {
        $jobId = $this->preview($type, $csv);

        return $this->postJson("/api/admin/import/{$jobId}/confirm", ['mode' => $mode, 'mapping' => $mapping])
            ->assertOk()
            ->json('data');
    }

    private function preview(string $type, string $csv): int
    {
        $path = storage_path('framework/testing/teacher-import-key.csv');
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $csv);

        return (int) $this->post('/api/admin/import/preview', [
            'data_type' => $type,
            'file' => new UploadedFile($path, 'teacher-import-key.csv', 'text/csv', null, true),
        ])->assertCreated()->json('data.id');
    }
}
