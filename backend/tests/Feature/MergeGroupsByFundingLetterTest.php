<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Person;
use App\Models\Student;
use App\Support\Groups\GroupDependencies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Слияние групп «А» и «Б» и снятие буквы.
 *
 * Данные вымышленные. Проверяется не столько результат, сколько **порядок**:
 * группа удаляется только после того, как на неё перестало что-либо ссылаться.
 * На `groups` семь каскадов, и удаление непустой группы уносит с собой журнал и
 * расписание молча.
 */
class MergeGroupsByFundingLetterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pair_becomes_one_group_without_the_letter(): void
    {
        $keeper = $this->makeGroup('Хореографическое творчество А, набор 2026');
        $other = $this->makeGroup('Хореографическое творчество Б, набор 2026');
        $stays = $this->makeStudent($keeper);
        $moves = $this->makeStudent($other);

        $this->artisan('groups:merge-funding-letter --apply')->assertSuccessful();

        $this->assertSame('Хореографическое творчество, набор 2026', $keeper->refresh()->name);
        $this->assertNull(Group::find($other->id));
        $this->assertSame($keeper->id, $stays->refresh()->group_id);
        $this->assertSame($keeper->id, $moves->refresh()->group_id, 'студент «Б» обязан переехать, а не исчезнуть');
    }

    public function test_a_lonely_letter_is_only_renamed(): void
    {
        $group = $this->makeGroup('Театральное творчество А, набор 2025');
        $student = $this->makeStudent($group);

        $this->artisan('groups:merge-funding-letter --apply')->assertSuccessful();

        $this->assertSame('Театральное творчество, набор 2025', $group->refresh()->name);
        $this->assertSame($group->id, $student->refresh()->group_id);
        $this->assertSame(1, Group::count());
    }

    public function test_a_taken_target_name_stops_the_pair(): void
    {
        // Две группы с одним именем ломают импорт студентов: он ищет группу по
        // названию. Лучше отказаться и сказать, чем завести двойника.
        $this->makeGroup('Хореографическое творчество, набор 2026');
        $keeper = $this->makeGroup('Хореографическое творчество А, набор 2026');
        $other = $this->makeGroup('Хореографическое творчество Б, набор 2026');

        $this->artisan('groups:merge-funding-letter --apply')
            ->expectsOutputToContain('имя уже занято')
            ->assertSuccessful();

        $this->assertSame('Хореографическое творчество А, набор 2026', $keeper->refresh()->name);
        $this->assertNotNull(Group::find($other->id));
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $keeper = $this->makeGroup('Хореографическое творчество А, набор 2026');
        $other = $this->makeGroup('Хореографическое творчество Б, набор 2026');
        $moves = $this->makeStudent($other);

        $this->artisan('groups:merge-funding-letter')->assertSuccessful();

        $this->assertSame('Хореографическое творчество А, набор 2026', $keeper->refresh()->name);
        $this->assertNotNull(Group::find($other->id));
        $this->assertSame($other->id, $moves->refresh()->group_id);
    }

    public function test_only_takes_a_single_pair(): void
    {
        $touched = $this->makeGroup('Хореографическое творчество А, набор 2026');
        $untouched = $this->makeGroup('Театральное творчество А, набор 2026');

        $this->artisan('groups:merge-funding-letter --only=Хореографическое --apply')->assertSuccessful();

        $this->assertSame('Хореографическое творчество, набор 2026', $touched->refresh()->name);
        $this->assertSame('Театральное творчество А, набор 2026', $untouched->refresh()->name);
    }

    public function test_a_group_that_still_holds_a_student_is_not_empty(): void
    {
        $group = $this->makeGroup('Хореографическое творчество Б, набор 2026');
        $this->makeStudent($group);

        $left = GroupDependencies::leftovers($group->id);

        $this->assertArrayHasKey('students', $left);
        $this->assertSame(1, $left['students']);
        $this->assertStringContainsString('students (1)', GroupDependencies::describe($left));
    }

    public function test_an_empty_group_has_nothing_leaning_on_it(): void
    {
        $group = $this->makeGroup('Хореографическое творчество Б, набор 2026');

        $this->assertSame([], GroupDependencies::leftovers($group->id));
    }

    /**
     * Если в схеме появится новый внешний ключ на `groups`, а в списке его не
     * будет, проверка «на группу больше ничего не ссылается» станет неправдой и
     * промолчит. Пусть лучше упадёт этот тест.
     */
    public function test_the_list_of_dependants_matches_the_schema(): void
    {
        ['columns' => $found, 'nullified' => $nullified] = $this->foreignKeysToGroups();

        ksort($found);
        $known = GroupDependencies::TABLES;
        ksort($known);

        $this->assertSame(
            $known,
            $found,
            'Список таблиц в GroupDependencies разошёлся со схемой: удаление группы снесёт каскадом то, чего никто не проверил.',
        );

        $this->assertSame(
            $nullified,
            $this->sorted(GroupDependencies::NULLIFIED),
            'Список NULLIFIED разошёлся со схемой. Ссылка, сменившая правило удаления, опаснее новой: '
            .'`SET NULL` не уносит строку, а оставляет её без смысла, и снаружи это выглядит исправным.',
        );
    }

    /**
     * Внешние ключи на `groups` прямо из схемы: какая колонка и какое правило
     * удаления. Читается схема, а не память: список, который сверяют руками,
     * однажды устареет и промолчит.
     *
     * @return array{columns: array<string, string>, nullified: list<string>}
     */
    private function foreignKeysToGroups(): array
    {
        $columns = [];
        $nullified = [];

        foreach (Schema::getTableListing() as $table) {
            $table = Str::afterLast($table, '.');

            foreach (Schema::getForeignKeys($table) as $key) {
                if (Str::afterLast($key['foreign_table'], '.') !== 'groups') {
                    continue;
                }

                $columns[$table] = $key['columns'][0];

                if (Str::contains(Str::lower((string) $key['on_delete']), 'null')) {
                    $nullified[] = $table;
                }
            }
        }

        return ['columns' => $columns, 'nullified' => $this->sorted($nullified)];
    }

    /** @param  list<string>  $values @return list<string> */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    private function makeGroup(string $name): Group
    {
        return Group::create([
            'name' => $name,
            'specialty' => 'Народное художественное творчество',
            'year_start' => 2026,
        ]);
    }

    private function makeStudent(Group $group): Student
    {
        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'last_name' => 'Ковалёва',
            'first_name' => 'Полина',
            'birth_date' => '2008-03-14',
            'status' => 'active',
        ]);

        return Student::create([
            'person_id' => $person->id,
            'group_id' => $group->id,
            'last_name' => 'Ковалёва',
            'first_name' => 'Полина',
            'birth_date' => '2008-03-14',
            'status' => 'active',
        ]);
    }
}
