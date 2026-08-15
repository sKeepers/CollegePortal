<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Одна учебная карточка на учётную запись.
 *
 * `User::teacher()` и `User::student()` — это `hasOne`, то есть приложение и так
 * считает связь единственной, а `StoreTeacherRequest` и `UpdateTeacherRequest`
 * объявляют `unique:teachers,user_id`. Правило было, гарантии не было: дубль
 * заводился в обход формы.
 *
 * На стенде их сделали два инструмента подряд. `portal:merge-accounts` переносил
 * на выжившую учётную запись **все** карточки проигравших, не глядя, есть ли у
 * неё своя; демонстрационный набор потом привязывал к той же записи свою.
 * Получалось две, `hasOne` брал первую — и у `teacher@local` кабинет с журналом
 * выглядели пустыми, хотя занятия, журнал и нагрузка висели на второй карточке.
 *
 * Замер на стенде 16.08.2026: у `teacher@local` карточка `2` (UAT) не
 * упоминалась ни в одной из десяти таблиц, ссылающихся на `teachers`, а на
 * карточке `5` были 21 занятие, 21 запись журнала, 16 строк нагрузки, группа,
 * пропуск и 16 проходов. У `student@local` — то же самое: 27 оценок, 57 отметок
 * и 26 проходов на одной карточке и ничего на другой.
 */
return new class extends Migration
{
    /**
     * Таблицы, по которым видно, что карточка работает. Список нарочно короткий:
     * это не полный перечень ссылок, а признак «за карточкой стоит работа»,
     * и нужен он лишь как запасное правило.
     *
     * @var array<string, array<int, string>>
     */
    private const WORK = [
        'teachers' => ['schedule_lessons', 'journal_lessons', 'teaching_load_items'],
        'students' => ['attendance', 'journal_grades', 'grades'],
    ];

    public function up(): void
    {
        foreach (self::WORK as $table => $workTables) {
            $this->detachDuplicates($table, $workTables);
            $this->addUniqueIndex($table);
        }
    }

    /**
     * Откат снимает только запрет. Вернуть отвязанные карточки он не может и не
     * должен: какая из двух была лишней — решение, а не состояние, и повторять
     * его задом наперёд значило бы восстанавливать поломку.
     */
    public function down(): void
    {
        foreach (array_keys(self::WORK) as $table) {
            DB::statement("DROP INDEX IF EXISTS {$table}_user_id_unique_active");
        }
    }

    /**
     * @param  array<int, string>  $workTables
     */
    private function detachDuplicates(string $table, array $workTables): void
    {
        $duplicates = DB::table($table)
            ->select('user_id')
            ->whereNotNull('user_id')
            ->whereNull('deleted_at')
            ->groupBy('user_id')
            ->havingRaw('count(*) > 1')
            ->pluck('user_id');

        foreach ($duplicates as $userId) {
            $cards = DB::table($table)
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get(['id', 'person_id']);

            $keep = $this->cardToKeep($table, $workTables, (int) $userId, $cards);

            DB::table($table)
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->where('id', '!=', $keep)
                ->update(['user_id' => null, 'updated_at' => now()]);
        }
    }

    /**
     * Какая карточка остаётся за учётной записью.
     *
     * Сначала — та, чей человек совпадает с человеком учётной записи: запись уже
     * говорит, чья она, и спорить с ней незачем. На стенде это правило разрешило
     * оба случая однозначно и совпало с тем, где лежала работа.
     *
     * Если совпадения нет или их несколько — та, за которой стоит работа.
     * Если и тут поровну — самая ранняя: она старше, и на неё скорее ссылались
     * из мест, о которых мы не знаем.
     *
     * @param  array<int, string>  $workTables
     * @param  \Illuminate\Support\Collection<int, object>  $cards
     */
    private function cardToKeep(string $table, array $workTables, int $userId, $cards): int
    {
        $personId = DB::table('users')->where('id', $userId)->value('person_id');

        if ($personId !== null) {
            $matching = $cards->where('person_id', $personId);

            if ($matching->count() === 1) {
                return (int) $matching->first()->id;
            }
        }

        $column = $table === 'teachers' ? 'teacher_id' : 'student_id';

        $weighted = $cards->map(fn (object $card): array => [
            'id' => (int) $card->id,
            'work' => collect($workTables)->sum(
                fn (string $workTable): int => DB::table($workTable)->where($column, $card->id)->count()
            ),
        ]);

        $most = $weighted->max('work');

        return (int) $weighted->where('work', $most)->sortBy('id')->first()['id'];
    }

    /**
     * Запрет частичный: мягко удалённая карточка сохраняет `user_id`, и без
     * условия на `deleted_at` учётная запись не смогла бы получить новую взамен
     * удалённой. `WHERE` в частичном индексе понимают и PostgreSQL, и SQLite.
     */
    private function addUniqueIndex(string $table): void
    {
        DB::statement(
            "CREATE UNIQUE INDEX {$table}_user_id_unique_active ON {$table} (user_id) ".
            'WHERE user_id IS NOT NULL AND deleted_at IS NULL'
        );
    }
};
