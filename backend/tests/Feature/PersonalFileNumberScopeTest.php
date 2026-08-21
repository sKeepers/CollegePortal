<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Номер личного дела уникален в пределах первой буквы фамилии.
 *
 * Выяснено в учебной части 21.08.2026: у каждой буквы алфавита своя нумерация.
 * Поэтому Иванов и Петров могут законно носить один и тот же номер, а два
 * Ивановых — нет. На настоящих списках 2026-2027 номер повторяется 108 раз, и
 * все эти повторы правильные; неправильных там осталось два, оба внутри буквы.
 */
class PersonalFileNumberScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_the_same_number_is_allowed_on_a_different_letter(): void
    {
        $group = $this->createGroup('М-301');
        $this->createStudent($group, 'Иванов', '380');

        $this->postJson('/api/students', [
            'group_id' => $group->id,
            'last_name' => 'Петров',
            'first_name' => 'Пётр',
            'status' => 'active',
            'personal_file_number' => '380',
        ])->assertCreated();

        $this->assertSame(2, Student::query()->where('personal_file_number', '380')->count());
    }

    public function test_the_same_number_on_the_same_letter_is_refused(): void
    {
        $group = $this->createGroup('М-302');
        $this->createStudent($group, 'Проверкина', '380');

        $this->postJson('/api/students', [
            'group_id' => $group->id,
            'last_name' => 'Пробникова',
            'first_name' => 'Юлия',
            'status' => 'active',
            'personal_file_number' => '380',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.personal_file_number.0',
                'Номер личного дела 380 на букву «П» уже занят: Проверкина Проверочная. '
                .'У каждой буквы своя нумерация, повторяться номер внутри буквы не может.');

        $this->assertSame(1, Student::query()->where('personal_file_number', '380')->count());
    }

    public function test_a_card_may_keep_its_own_number_when_edited(): void
    {
        $group = $this->createGroup('М-303');
        $student = $this->createStudent($group, 'Чистякова', '115');

        // Своя же запись мешать не должна: номер у карточки остаётся прежним.
        $this->patchJson("/api/students/{$student->id}", ['first_name' => 'Елизавета'])
            ->assertOk();

        $this->patchJson("/api/students/{$student->id}", ['personal_file_number' => '115'])
            ->assertOk()
            ->assertJsonPath('data.personal_file_number', '115');
    }

    public function test_renaming_into_a_taken_letter_is_refused(): void
    {
        $group = $this->createGroup('М-304');
        $this->createStudent($group, 'Чекушкина', '115');
        $moving = $this->createStudent($group, 'Иванова', '115');

        // Пока фамилия на «И», номер свободен. Смена фамилии на «Ч» упирается в
        // занятый номер — букву берём из запроса, а не из старой карточки.
        $this->patchJson("/api/students/{$moving->id}", ['last_name' => 'Чистякова', 'personal_file_number' => '115'])
            ->assertStatus(422);
    }

    public function test_the_import_refuses_a_taken_number_within_the_letter(): void
    {
        $group = $this->createGroup('М-305');
        $this->createStudent($group, 'Чекушкина', '115');

        $csv = "Фамилия;Имя;Группа;Статус;Алфавитный классификатор\n"
            ."Чистякова;Елизавета;{$group->name};active;115\n";

        $this->post('/api/students/import', [
            'file' => UploadedFile::fake()->createWithContent('students.csv', $csv),
        ])->assertOk();

        $this->assertNull(Student::query()->where('last_name', 'Чистякова')->first(),
            'Загрузка поставила второе дело под тем же номером на ту же букву');
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
