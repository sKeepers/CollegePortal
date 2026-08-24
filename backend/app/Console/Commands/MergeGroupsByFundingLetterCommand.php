<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\Student;
use App\Support\Groups\GroupDependencies;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Слить группы «А» и «Б» и снять букву из названия.
 *
 * Буква никогда не означала учебную группу: в списках учебной части рядом с ней
 * написано «(бюджетная)» и «(платная)». Владелец подтвердил 24.08.2026 —
 * платники и бюджетники учатся вместе, а отмечать основу нужно в карточке
 * студента. Основа туда уже перенесена (`students:funding-from-group-letter`),
 * поэтому буква стала дублем поля, и её можно снимать без потери.
 *
 * **Момент выбран не случайно.** К этим группам, кроме студентов, не привязано
 * ничего: ни занятия журнала, ни расписание, ни шаблоны, ни экзамены, ни
 * нагрузка — проверено запросом по всем девяти внешним ключам на `groups`.
 * После 1 сентября пришлось бы переносить ещё и это.
 *
 * Порядок внутри пары — он же порядок безопасности:
 *
 * 1. студенты «Б» переходят в «А»;
 * 2. у «Б» проверяется, что на неё **больше ничего не ссылается** — запросом, а
 *    не предположением. На `groups` семь внешних ключей с `ON DELETE CASCADE`:
 *    удалить непустую группу значит молча снести её студентов, журнал и
 *    расписание;
 * 3. только пустая «Б» удаляется;
 * 4. «А» переименовывается без буквы.
 *
 * Целевое имя проверяется на занятость заранее: если группа без буквы с таким
 * названием уже есть, пара пропускается и уходит в отчёт. Две группы с одним
 * именем ломают импорт студентов — он ищет группу по названию.
 */
class MergeGroupsByFundingLetterCommand extends Command
{
    protected $signature = 'groups:merge-funding-letter
        {--only= : Взять только пары, чьё будущее имя содержит эту строку — проба}
        {--apply : Записать изменения; без флага команда только считает}';

    protected $description = 'Слить группы «А» и «Б» в одну и убрать букву из названия.';

    private const NAME = '/^(?<base>.+?)\s(?<letter>[АБ]),\s*набор\s(?<year>\d{4})$/u';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $only = trim((string) $this->option('only'));

        $pairs = $this->pairs($only);

        if ($pairs === []) {
            $this->warn('Групп с буквой не нашлось — работать не с чем.');

            return self::SUCCESS;
        }

        $rows = [];
        $moved = 0;
        $removed = 0;
        $renamed = 0;
        $skipped = [];

        foreach ($pairs as $target => $group) {
            $keeper = $group['keeper'];
            $others = $group['others'];

            if (Group::query()->where('name', $target)->whereKeyNot($keeper->id)->exists()) {
                $skipped[] = [$target, 'имя уже занято другой группой'];

                continue;
            }

            $toMove = Student::query()
                ->whereIn('group_id', collect($others)->pluck('id'))
                ->count();

            $rows[] = [
                $target,
                $keeper->name,
                implode(', ', array_map(fn (Group $g): string => $g->name, $others)) ?: '—',
                $toMove,
            ];

            $moved += $toMove;
            $removed += count($others);
            $renamed++;

            if (! $apply) {
                continue;
            }

            DB::transaction(function () use ($keeper, $others, $target): void {
                foreach ($others as $other) {
                    Student::query()->where('group_id', $other->id)->update(['group_id' => $keeper->id]);

                    $this->assertNothingLeans($other);

                    Group::query()->whereKey($other->id)->delete();
                }

                $keeper->fill(['name' => $target])->save();
            });
        }

        $this->info($apply ? 'Слито:' : 'Холостой проход, слило бы:');
        $this->table(['Будущее имя', 'Остаётся', 'Уходит', 'Переезжает студентов'], $rows);
        $this->line(sprintf(
            'Пар: %d, переезжает студентов: %d, удаляется групп: %d, переименовывается: %d.',
            count($rows),
            $moved,
            $removed,
            $renamed,
        ));

        foreach ($skipped as [$target, $why]) {
            $this->warn(sprintf('Пропущено «%s»: %s.', $target, $why));
        }

        if (! $apply) {
            $this->comment('Это был холостой проход. Записать — тот же вызов с --apply.');
        }

        return self::SUCCESS;
    }

    /**
     * Пары «будущее имя» → какая группа остаётся и какие уходят.
     *
     * Остаётся «А», если она есть: в ней всегда больше людей, и меньше строк
     * придётся двигать. Одиночка остаётся сама собой — сливать её не с чем,
     * ей нужно только переименование.
     *
     * @return array<string, array{keeper: Group, others: list<Group>}>
     */
    private function pairs(string $only): array
    {
        $byTarget = [];

        foreach (Group::query()->orderBy('id')->get() as $group) {
            if (preg_match(self::NAME, (string) $group->name, $match) !== 1) {
                continue;
            }

            $target = sprintf('%s, набор %s', $match['base'], $match['year']);

            if ($only !== '' && ! str_contains($target, $only)) {
                continue;
            }

            $byTarget[$target][$match['letter']] = $group;
        }

        $pairs = [];

        foreach ($byTarget as $target => $letters) {
            ksort($letters);
            $keeper = $letters['А'] ?? reset($letters);
            $others = array_values(array_filter(
                $letters,
                fn (Group $group): bool => $group->id !== $keeper->id,
            ));

            $pairs[$target] = ['keeper' => $keeper, 'others' => $others];
        }

        return $pairs;
    }

    /**
     * Убедиться, что на группу больше ничего не ссылается.
     *
     * Проверка запросом, а не доверием к предыдущему шагу: цена ошибки —
     * каскадное удаление журнала и расписания вместе с группой.
     */
    private function assertNothingLeans(Group $group): void
    {
        $leftovers = GroupDependencies::leftovers((int) $group->id);

        if ($leftovers !== []) {
            throw new RuntimeException(sprintf(
                'Группа «%s» не удалена: на неё ещё ссылается %s. Удаление снесло бы эти строки каскадом.',
                $group->name,
                GroupDependencies::describe($leftovers),
            ));
        }
    }
}
