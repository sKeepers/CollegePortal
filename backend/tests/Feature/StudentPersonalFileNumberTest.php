<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Номер личного дела студента.
 *
 * В бумажных списках колледжа он стоит в столбце «Алфавитный классификатор» с
 * кодом формы `02-20`. Владелец 21.08.2026: этот номер привязан к номеру дела и
 * к номеру зачётной книжки — то есть номер один, а не три разных.
 *
 * Уникальным он **не объявлен**, и это решение владельца, а не недосмотр: на
 * настоящих списках 2026-2027 номер повторяется 109 раз из 591, и уникальный
 * индекс не дал бы загрузить контингент вовсе. Границы уникальности уточняются
 * в учебной части.
 */
class StudentPersonalFileNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_the_number_is_stored_and_returned_with_the_card(): void
    {
        $group = $this->createGroup('М-101');

        $response = $this->postJson('/api/students', [
            'group_id' => $group->id,
            'last_name' => 'Иванов',
            'first_name' => 'Дмитрий',
            'status' => 'active',
            'personal_file_number' => '330',
        ])->assertCreated();

        $this->assertSame('330', $response->json('data.personal_file_number'));
        $this->assertDatabaseHas('students', ['id' => $response->json('data.id'), 'personal_file_number' => '330']);

        $this->patchJson('/api/students/'.$response->json('data.id'), ['personal_file_number' => '331'])
            ->assertOk()
            ->assertJsonPath('data.personal_file_number', '331');
    }

    public function test_the_same_number_may_stand_at_two_students(): void
    {
        $group = $this->createGroup('М-102');

        foreach (['Иванов', 'Петров'] as $lastName) {
            $this->postJson('/api/students', [
                'group_id' => $group->id,
                'last_name' => $lastName,
                'first_name' => 'Проверочный',
                'status' => 'active',
                'personal_file_number' => '286',
            ])->assertCreated();
        }

        // Повтор законен: фамилии на разные буквы, а у каждой буквы алфавита
        // своя нумерация (учебная часть, 21.08.2026). Внутри одной буквы такой
        // повтор уже не пройдёт — это проверяет PersonalFileNumberScopeTest.
        $this->assertSame(2, Student::query()->where('personal_file_number', '286')->count());
    }

    public function test_a_student_is_found_by_the_number(): void
    {
        $group = $this->createGroup('М-104');
        Student::create(['group_id' => $group->id, 'last_name' => 'Иванов', 'first_name' => 'Дмитрий', 'status' => 'active', 'personal_file_number' => '186']);
        Student::create(['group_id' => $group->id, 'last_name' => 'Петров', 'first_name' => 'Пётр', 'status' => 'active', 'personal_file_number' => '187']);

        $this->getJson('/api/students?search=186')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.last_name', 'Иванов');
    }

    public function test_the_import_takes_the_number_from_the_alphabetical_classifier_column(): void
    {
        $group = $this->createGroup('М-103');

        $csv = "Фамилия;Имя;Группа;Статус;Алфавитный классификатор\n"
            ."Сидоров;Сидор;{$group->name};active;601\n";

        $this->post('/api/students/import', [
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('students.csv', $csv),
        ])->assertOk();

        $this->assertDatabaseHas('students', ['last_name' => 'Сидоров', 'personal_file_number' => '601']);
    }

    private function createGroup(string $name): Group
    {
        return Group::create([
            'name' => $name,
            'specialty' => 'Инструментальное исполнительство',
            'course' => 1,
            'year_start' => 2026,
        ]);
    }
}
