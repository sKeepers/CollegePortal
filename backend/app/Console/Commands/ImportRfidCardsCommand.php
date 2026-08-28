<?php

namespace App\Console\Commands;

use App\Models\Person;
use App\Models\RfidCard;
use App\Services\RfidCardService;
use App\Support\Rfid\CarddexPeopleCsv;
use App\Support\Rfid\CardNumber;
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

    /** @var array<string, array<int, Person>>|null */
    private ?array $people = null;

    /** @var array<string, array<int, Person>>|null */
    private ?array $byName = null;

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
        $second = [];
        $again = 0;
        $missing = [];
        $ambiguous = [];
        $failed = [];

        foreach ($rows as $row) {
            $people = $this->findPeople($row);

            if ($people === []) {
                $missing[] = $this->fio($row);

                continue;
            }

            // Тёзки пропускаются поимённо, а не «как-нибудь»: привязать карту
            // не тому человеку хуже, чем не привязать вовсе, и обнаружится это
            // у турникета, а не здесь.
            if (count($people) > 1) {
                $ambiguous[] = $this->fio($row).' — '.count($people).' совпадения';

                continue;
            }

            $person = $people[0];
            $existing = RfidCard::query()
                ->where('person_id', $person->id)
                ->where('status', RfidCard::STATUS_ISSUED)
                ->first();

            // Та же карта у того же человека — это повторный запуск, а не
            // отказ. Без этой ветки второй проход по тем же файлам печатал 236
            // «Карта уже выдана» и выглядел бы полной неудачей, хотя не сделал
            // ничего и не должен был.
            if ($this->alreadyBound($person, $row['card'])) {
                $again++;

                continue;
            }

            try {
                $cards->bind($person, $row['card'], null, 'Перенос из СКУД '.$row['department']);
                $bound++;
            } catch (Throwable $e) {
                $failed[] = $this->fio($row).' — '.$e->getMessage();

                continue;
            }

            // Вторая карта тому же человеку **привязывается**, а не
            // пропускается: 28.08.2026 владелец сказал, что на человека бывает
            // записано несколько карт, и запрет на вторую снят в
            // `RfidCardService`. Но в отчёт она идёт отдельной строкой — если
            // две карты сошлись на одном человеке по ошибке сопоставления,
            // молчание сделало бы эту ошибку ненаходимой.
            if ($existing !== null) {
                $second[] = $this->fio($row).' — вторая карта: была '.$existing->uid.', добавлена '.$row['card'];
            }
        }

        if ($dryRun) {
            DB::rollBack();
        }

        $this->line('Строк в выгрузке: '.count($rows));
        $this->line('Карт привязано: '.$bound);
        $this->line('Из них вторая карта тому же человеку: '.count($second));
        $this->line('Уже было привязано раньше: '.$again);
        $this->line('Человек не найден: '.count($missing));
        $this->line('Тёзки, пропущены: '.count($ambiguous));
        $this->line('Отказов: '.count($failed));

        // Пропущенное печатается поимённо, а не числом: число говорит «что-то
        // не сошлось», а список говорит, что именно чинить.
        foreach (['Не найдены' => $missing, 'Тёзки' => $ambiguous, 'Вторая карта' => $second, 'Отказы' => $failed] as $title => $list) {
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
     * **Регистр опускает PHP, а не база, и это не вкусовщина.** Первая
     * редакция сравнивала `lower(trim(...))` в запросе против
     * `mb_strtolower()` в коде — и ствол покраснел на SQLite при зелёном
     * PostgreSQL. Причина замерена прямо: `lower('Иванов')` в SQLite
     * возвращает `Иванов` — эта функция знает только латиницу, — а
     * `mb_strtolower('Иванов')` даёт `иванов`. Сравнение не сходилось
     * никогда, человек не находился, и команда не доходила до вывода итога.
     * На PostgreSQL то же выражение работает, поэтому прогон «поближе к бою»
     * этого не показывал: **зелёный на одном движке ничего не обещает на
     * другом, в любую сторону.**
     *
     * Заодно это один запрос вместо одного на строку.
     *
     * @return array<int, Person>
     */
    private function findPeople(array $row): array
    {
        // Цифра вместо отчества — не имя, а пометка выгрузки. Владелец
        // 28.08.2026: «на человека оказалось записано больше одной карты,
        // поэтому добавил цифру». В кадровой выгрузке так помечены семь строк
        // — три карты одного преподавателя, две другого, две третьего.
        //
        // По такой строке ищем по фамилии и имени, и **отказ при неоднозначности
        // остаётся**: если под парой окажется двое, строка по-прежнему уходит в
        // «тёзки». Замер по контингенту стенда: пара «фамилия + имя»
        // неоднозначна у четверых из 596, поэтому отбрасывать отчество там,
        // где оно есть, нельзя — только там, где вместо него цифра.
        if ($this->isCardMarker($row['middle_name'])) {
            return $this->byName()[$this->key($row['last_name'], $row['first_name'], null)] ?? [];
        }

        return $this->index()[$this->key($row['last_name'], $row['first_name'], $row['middle_name'])] ?? [];
    }

    private function isCardMarker(string $middleName): bool
    {
        return $middleName !== '' && ctype_digit($middleName);
    }

    /** @return array<string, array<int, Person>> */
    private function byName(): array
    {
        if ($this->byName !== null) {
            return $this->byName;
        }

        $this->byName = [];

        foreach ($this->index() as $people) {
            foreach ($people as $person) {
                $this->byName[$this->key($person->last_name, $person->first_name, null)][] = $person;
            }
        }

        return $this->byName;
    }

    /** @return array<string, array<int, Person>> */
    private function index(): array
    {
        if ($this->people !== null) {
            return $this->people;
        }

        $this->people = [];

        foreach (Person::query()->get(['id', 'last_name', 'first_name', 'middle_name']) as $person) {
            $this->people[$this->key($person->last_name, $person->first_name, $person->middle_name)][] = $person;
        }

        return $this->people;
    }

    private function key(?string $last, ?string $first, ?string $middle): string
    {
        return implode('|', array_map(
            fn (?string $part) => mb_strtolower(trim((string) $part)),
            [$last, $first, $middle],
        ));
    }

    /** Та же карта уже на руках у того же человека: повторный запуск. */
    private function alreadyBound(Person $person, string $card): bool
    {
        return RfidCard::query()
            ->where('person_id', $person->id)
            ->where('uid', CardNumber::normalize($card))
            ->where('status', RfidCard::STATUS_ISSUED)
            ->exists();
    }

    private function fio(array $row): string
    {
        return trim($row['last_name'].' '.$row['first_name'].' '.$row['middle_name']).' (строка '.$row['line'].')';
    }
}
