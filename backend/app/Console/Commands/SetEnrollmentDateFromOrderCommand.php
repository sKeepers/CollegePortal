<?php

namespace App\Console\Commands;

use App\Models\Student;
use Illuminate\Console\Command;

/**
 * Дата зачисления берётся из приказа о зачислении.
 *
 * Решение владельца от 23.08.2026. Своего столбца у даты зачисления в выгрузках
 * ФИС нет, а **даты начала обучения нет и в самих приказах** — проверены все
 * четыре за 2023-2026, единственная дата, которую приказ называет, это дата
 * самого приказа. Она в карточках уже стоит: у 588 студентов это дата приказа
 * своего набора, у пяти — своя, потому что зачислены отдельным приказом.
 *
 * Поэтому команда не вычисляет ничего и ничего не выдумывает: она переносит
 * `enrollment_order_date` в пустую `enrollment_date`. Заполненную не трогает —
 * если дату зачисления кто-то поставил руками, значит, знал зачем.
 *
 * Если владелец решит, что зачисление считается с 1 сентября, это будет другое
 * правило и другая команда: 1 сентября в приказах не написано.
 */
class SetEnrollmentDateFromOrderCommand extends Command
{
    protected $signature = 'students:enrollment-date-from-order
        {--limit=0 : Взять только первые N карточек — проба}
        {--apply : Записать изменения; без флага команда только считает}';

    protected $description = 'Проставить дату зачисления по дате приказа о зачислении.';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $apply = (bool) $this->option('apply');

        $students = Student::query()
            ->whereNull('archived_at')
            ->whereNull('enrollment_date')
            ->whereNotNull('enrollment_order_date')
            ->orderBy('id')
            ->when($limit > 0, fn ($query) => $query->limit($limit))
            ->get();

        $withoutOrder = Student::query()
            ->whereNull('archived_at')
            ->whereNull('enrollment_date')
            ->whereNull('enrollment_order_date')
            ->count();

        foreach ($students as $student) {
            if ($apply) {
                $student->fill(['enrollment_date' => $student->enrollment_order_date->toDateString()])->save();
            }
        }

        $this->info($apply ? 'Проставлено:' : 'Холостой проход, проставило бы:');
        $this->table(['Что', 'Карточек'], [
            ['дата зачисления взята из приказа', $students->count()],
            ['дата зачисления уже стояла', Student::query()->whereNull('archived_at')->whereNotNull('enrollment_date')->count() - ($apply ? $students->count() : 0)],
            ['приказа нет, брать неоткуда', $withoutOrder],
        ]);

        if ($withoutOrder > 0) {
            $this->warn('У '.$withoutOrder.' карточек нет даты приказа — дату зачисления им ставить не из чего.');
        }

        if (! $apply) {
            $this->comment('Это был холостой проход. Записать — тот же вызов с --apply.');
        }

        return self::SUCCESS;
    }
}
