<?php

namespace App\Console\Commands;

use App\Models\Person;
use App\Models\RfidCard;
use App\Services\RfidCardService;
use App\Support\Rfid\CarddexPeopleCsv;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Привязка карт СКУД к людям по кадровой выгрузке.
 *
 * Команда, а не экран, по той же причине, что и загрузка журнала: экрану нужно
 * право, праву — миграция и решение владельца о том, кому его выдать. Разовый
 * перенос этого не стоит.
 *
 * **Человека команда не заводит.** Строка, которой не нашлось человека в
 * портале, попадает в отчёт и пропускается: завести карту «на будущего»
 * означало бы держать номер, ни к кому не привязанный, а потом гадать, чей он.
 * Людей заводит кадровый импорт, и порядок здесь именно такой.
 */
class ImportRfidCardsCommand extends Command
{
    protected $signature = 'identity:import-cards
        {file : путь к кадровой выгрузке CARDDEX}
        {--dry-run : посчитать и откатить, ничего не записав}';

    protected $description = 'Завести карты СКУД по кадровой выгрузке и выдать их людям';

    public function handle(RfidCardService $cards): int
    {
        $file = (string) $this->argument('file');

        if (! is_readable($file)) {
            $this->error("Файл не читается: {$file}");

            return self::FAILURE;
        }

        try {
            $rows = CarddexPeopleCsv::rows($file);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            DB::beginTransaction();
        }

        $bound = 0;
        $already = [];
        $missing = [];
        $ambiguous = [];
        $failed = [];

        foreach ($rows as $row) {
            $people = $this->findPeople($row);

            if ($people->isEmpty()) {
                $missing[] = $this->fio($row);

                continue;
            }

            // Тёзки пропускаются поимённо, а не «как-нибудь»: привязать карту
            // не тому человеку хуже, чем не привязать вовсе, и обнаружится это
            // у турникета, а не здесь.
            if ($people->count() > 1) {
                $ambiguous[] = $this->fio($row).' — '.$people->count().' совпадения';

                continue;
            }

            $person = $people->first();
            $existing = RfidCard::query()
                ->where('person_id', $person->id)
                ->where('status', RfidCard::STATUS_ISSUED)
                ->first();

            // Вторая карта тому же человеку — это решение, а не мелочь, и
            // числом её показывать мало. В выгрузке 28.08.2026 такая строка
            // есть: один преподаватель числится с двумя картами, обе строки
            // сошлись по ФИО. Пропустить молча — значит оставить вопрос
            // «какая из двух у него на руках» ненайденным.
            if ($existing !== null) {
                $already[] = $this->fio($row).' — на руках карта '.$existing->uid.', из файла '.$row['card'];

                continue;
            }

            try {
                $cards->bind($person, $row['card'], null, 'Перенос из СКУД '.$row['department']);
                $bound++;
            } catch (Throwable $e) {
                $failed[] = $this->fio($row).' — '.$e->getMessage();
            }
        }

        if ($dryRun) {
            DB::rollBack();
        }

        $this->line('Строк в выгрузке: '.count($rows));
        $this->line('Карт привязано: '.$bound);
        $this->line('Уже была карта на руках: '.count($already));
        $this->line('Человек не найден: '.count($missing));
        $this->line('Тёзки, пропущены: '.count($ambiguous));
        $this->line('Отказов: '.count($failed));

        // Пропущенное печатается поимённо, а не числом: число говорит «что-то
        // не сошлось», а список говорит, что именно чинить.
        foreach (['Не найдены' => $missing, 'Тёзки' => $ambiguous, 'Уже с картой' => $already, 'Отказы' => $failed] as $title => $list) {
            if ($list === []) {
                continue;
            }

            $this->newLine();
            $this->line($title.':');

            foreach ($list as $item) {
                $this->line('  '.$item);
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->comment('Пробный проход: ничего не записано.');
        }

        return self::SUCCESS;
    }

    /**
     * Совпадение по трём частям имени, а не по двум.
     *
     * Замер по контингенту стенда 28.08.2026: из 596 студентов пара «фамилия +
     * имя» повторяется дважды и задевает четверых, а полных совпадений ФИО нет
     * ни одного. То есть отчество здесь не уточнение, а единственное, что
     * различает всех.
     *
     * @return \Illuminate\Support\Collection<int, Person>
     */
    private function findPeople(array $row)
    {
        return Person::query()
            ->whereRaw('lower(trim(last_name)) = ?', [mb_strtolower($row['last_name'])])
            ->whereRaw('lower(trim(first_name)) = ?', [mb_strtolower($row['first_name'])])
            ->whereRaw('lower(trim(coalesce(middle_name, \'\'))) = ?', [mb_strtolower($row['middle_name'])])
            ->get();
    }

    private function fio(array $row): string
    {
        return trim($row['last_name'].' '.$row['first_name'].' '.$row['middle_name']).' (строка '.$row['line'].')';
    }
}
