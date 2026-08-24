<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\Student;
use Illuminate\Console\Command;

/**
 * Перенести источник оплаты из буквы названия группы в поле карточки.
 *
 * Контингент заводили 22.08.2026 из списков учебной части, где параллельные
 * группы размечены буквой: «Группа А (бюджетная)», «Группа Б (платная)» —
 * в самом документе так и написано, в скобках рядом с буквой. Буква попала в
 * название группы, а поле `students.funding_form` осталось пустым **у всех 593**.
 *
 * Из-за этого источник оплаты хранился ровно в одном месте — в имени группы, —
 * и в выгрузке студентов его не было вовсе: на боевой сервер различие не уехало
 * бы. Слить группы, о чём просит владелец, раньше этого переноса значило бы
 * стереть «бюджет / платно» без возможности восстановить.
 *
 * Команда не угадывает. Она переносит только то, что записано буквой:
 *
 * - «А» → «Бюджет», «Б» → «Договор» (написание из справочника «Формы
 *   финансирования», чтобы в базе не завелось три написания одного);
 * - **группы без буквы не трогаются вовсе.** Их 46, в них 329 человек, и в
 *   документе про их основу не сказано ничего. Догадка «без буквы значит
 *   бюджет» правдоподобна, но приказ о зачислении её не подтверждает: разбор
 *   приказа расходится со списками в обе стороны, и это записано ещё 22.08;
 * - **заполненное не затирается.** Расхождение уже стоящего значения с буквой
 *   идёт в отчёт: его разбирает человек.
 */
class FundingFormFromGroupLetterCommand extends Command
{
    protected $signature = 'students:funding-from-group-letter
        {--limit=0 : Взять только первые N карточек — проба}
        {--apply : Записать изменения; без флага команда только считает}';

    protected $description = 'Перенести «бюджет / договор» из буквы названия группы в поле формы финансирования.';

    /** Что означает буква. В документе учебной части так и написано в скобках. */
    private const BY_LETTER = ['А' => 'Бюджет', 'Б' => 'Договор'];

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $apply = (bool) $this->option('apply');

        $letters = $this->lettersByGroup();

        $summary = ['scanned' => 0, 'with_letter' => 0, 'written' => 0, 'already_same' => 0, 'conflicts' => []];

        $students = Student::query()
            ->whereNotNull('person_id')
            ->whereNotNull('group_id')
            ->orderBy('id')
            ->get();

        foreach ($students as $student) {
            $summary['scanned']++;

            $value = $letters[$student->group_id] ?? null;

            if ($value === null) {
                continue;
            }

            $summary['with_letter']++;

            if ($limit > 0 && $summary['with_letter'] > $limit) {
                $summary['with_letter']--;
                break;
            }

            $current = trim((string) $student->funding_form);

            if ($current !== '') {
                if ($current === $value) {
                    $summary['already_same']++;
                } else {
                    $summary['conflicts'][] = ['student_id' => $student->id, 'was' => $current, 'letter_says' => $value];
                }

                continue;
            }

            $summary['written']++;

            if ($apply) {
                $student->fill(['funding_form' => $value])->save();
            }
        }

        $this->info($apply ? 'Записано:' : 'Холостой проход, записало бы:');
        $this->table(['Что', 'Карточек'], [
            ['просмотрено', $summary['scanned']],
            ['группа размечена буквой', $summary['with_letter']],
            ['форма финансирования записана', $summary['written']],
            ['уже стояло то же самое', $summary['already_same']],
            ['расхождение, оставлено человеку', count($summary['conflicts'])],
        ]);

        $this->line(sprintf(
            'Групп с буквой: %d. Группы без буквы не трогались: в них источник оплаты неизвестен.',
            count($letters),
        ));

        foreach ($summary['conflicts'] as $conflict) {
            $this->warn(sprintf(
                'Карточка %d: стоит «%s», буква группы говорит «%s».',
                $conflict['student_id'],
                $conflict['was'],
                $conflict['letter_says'],
            ));
        }

        if (! $apply) {
            $this->comment('Это был холостой проход. Записать — тот же вызов с --apply.');
        }

        return self::SUCCESS;
    }

    /**
     * Буква из названия группы → написание для карточки.
     *
     * Буква берётся из имени, потому что больше её взять неоткуда: отдельного
     * поля у группы нет, и заводилась она разбором документа. Шаблон узкий —
     * « А, набор» и « Б, набор», — чтобы буква в названии специальности не
     * попала под правило.
     *
     * @return array<int, string>
     */
    private function lettersByGroup(): array
    {
        $map = [];

        foreach (Group::query()->get(['id', 'name']) as $group) {
            if (preg_match('/\s([АБ]),\s*набор\s/u', (string) $group->name, $match) !== 1) {
                continue;
            }

            $map[(int) $group->id] = self::BY_LETTER[$match[1]];
        }

        return $map;
    }
}
