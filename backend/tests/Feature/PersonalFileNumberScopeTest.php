<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Номер личного дела и его буква.
 *
 * У каждой буквы алфавита своя нумерация (учебная часть, 21.08.2026). Поэтому
 * два дела с номером 380 — на «И» и на «П» — это норма, а два дела с номером 380
 * на «П» — ошибка.
 *
 * **Буква закрепляется за делом при заведении и при смене фамилии не меняется.**
 * Владелец показал случай: студентка с номером 115 была Ильясовой, вышла замуж и
 * стала Черковой — номер остался прежним, дело так и числится по «И». Если бы
 * буква выводилась из текущей фамилии, портал объявил бы ложный конфликт с чужим
 * делом 115 на «Ч», а сам номер менял бы принадлежность при каждом замужестве.
 *
 * Фамилии здесь выдуманные: настоящих ПДн в репозитории быть не должно.
 */
class PersonalFileNumberScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_the_letter_comes_from_the_surname_when_the_file_is_opened(): void
    {
        $group = $this->createGroup('М-301');

        $response = $this->postJson('/api/students', [
            'group_id' => $group->id,
            'last_name' => 'Пробникова',
            'first_name' => 'Проверочная',
            'status' => 'active',
            'personal_file_number' => '380',
        ])->assertCreated();

        $this->assertSame('П', $response->json('data.personal_file_letter'));
    }

    public function test_the_same_number_is_allowed_on_a_different_letter(): void
    {
        $group = $this->createGroup('М-302');
        $this->createStudent($group, 'Аистов', '380');

        $this->postJson('/api/students', [
            'group_id' => $group->id,
            'last_name' => 'Барсуков',
            'first_name' => 'Проверочный',
            'status' => 'active',
            'personal_file_number' => '380',
        ])->assertCreated();

        $this->assertSame(2, Student::query()->where('personal_file_number', '380')->count());
    }

    public function test_the_same_number_on_the_same_letter_is_refused(): void
    {
        $group = $this->createGroup('М-303');
        $this->createStudent($group, 'Проверкина', '380');

        $this->postJson('/api/students', [
            'group_id' => $group->id,
            'last_name' => 'Пробникова',
            'first_name' => 'Проверочная',
            'status' => 'active',
            'personal_file_number' => '380',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.personal_file_number.0',
                'Номер личного дела 380 по букве «П» уже занят: Проверкина Проверочная. '
                .'У каждой буквы своя нумерация, повторяться номер внутри буквы не может.');

        $this->assertSame(1, Student::query()->where('personal_file_number', '380')->count());
    }

    public function test_a_marriage_keeps_the_file_where_it_was_opened(): void
    {
        $group = $this->createGroup('М-304');

        // Дело заведено под фамилией на «И».
        $married = $this->createStudent($group, 'Ильясова', '115');
        $this->assertSame('И', $married->fresh()->personal_file_letter);

        // Другая студентка с тем же номером, но по букве «Ч».
        $this->createStudent($group, 'Чистякова', '115');

        // Замужество: фамилия становится на «Ч», а дело остаётся по «И» — и это
        // не конфликт с чужим 115 на «Ч».
        $this->patchJson("/api/students/{$married->id}", ['last_name' => 'Чекушкина'])->assertOk();

        $married->refresh();
        $this->assertSame('Чекушкина', $married->last_name);
        $this->assertSame('И', $married->personal_file_letter, 'Смена фамилии перенесла дело на другую букву');
        $this->assertSame('115', $married->personal_file_number);
    }

    public function test_a_new_student_without_a_number_gets_the_next_free_one(): void
    {
        $group = $this->createGroup('М-305');
        $this->createStudent($group, 'Аистов', '7');
        $this->createStudent($group, 'Барсуков', '40');

        $response = $this->postJson('/api/students', [
            'group_id' => $group->id,
            'last_name' => 'Аистова',
            'first_name' => 'Проверочная',
            'status' => 'active',
        ])->assertCreated();

        // Нумерация идёт в пределах своей буквы: у «А» занята семёрка, значит
        // следующее дело — восьмое, а сороковое у «Б» тут ни при чём.
        $this->assertSame('А', $response->json('data.personal_file_letter'));
        $this->assertSame('8', $response->json('data.personal_file_number'));
    }

    public function test_the_first_file_of_a_letter_starts_from_one(): void
    {
        $group = $this->createGroup('М-306');

        $response = $this->postJson('/api/students', [
            'group_id' => $group->id,
            'last_name' => 'Ясенев',
            'first_name' => 'Проверочный',
            'status' => 'active',
        ])->assertCreated();

        $this->assertSame('Я', $response->json('data.personal_file_letter'));
        $this->assertSame('1', $response->json('data.personal_file_number'));
    }

    public function test_the_import_takes_an_explicit_letter_and_refuses_a_taken_one(): void
    {
        $group = $this->createGroup('М-307');
        $this->createStudent($group, 'Чистякова', '115');

        // Дело заведено под прежней фамилией — букву даёт файл.
        $csv = "Фамилия;Имя;Группа;Статус;Алфавитный классификатор;Буква личного дела\n"
            ."Чекушкина;Проверочная;{$group->name};active;115;И\n";

        $this->post('/api/students/import', [
            'file' => UploadedFile::fake()->createWithContent('students.csv', $csv),
        ])->assertOk();

        $this->assertSame('И', Student::query()->where('last_name', 'Чекушкина')->value('personal_file_letter'));

        // А без буквы та же строка упирается в занятый номер по «Ч».
        $csvWithoutLetter = "Фамилия;Имя;Группа;Статус;Алфавитный классификатор\n"
            ."Черемухина;Проверочная;{$group->name};active;115\n";

        $this->post('/api/students/import', [
            'file' => UploadedFile::fake()->createWithContent('students2.csv', $csvWithoutLetter),
        ])->assertOk();

        $this->assertNull(Student::query()->where('last_name', 'Черемухина')->first());
    }

    private function createGroup(string $name): Group
    {
        return Group::create(['name' => $name, 'specialty' => 'Проверка', 'course' => 1, 'year_start' => 2026]);
    }

    private function createStudent(Group $group, string $lastName, string $number): Student
    {
        return Student::create([
            'group_id' => $group->id,
            'last_name' => $lastName,
            'first_name' => 'Проверочная',
            'status' => 'active',
            'personal_file_number' => $number,
        ]);
    }
}
