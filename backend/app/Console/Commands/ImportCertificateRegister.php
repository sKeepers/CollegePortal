<?php

namespace App\Console\Commands;

use App\Models\Student;
use App\Models\StudentCertificate;
use App\Support\Csv\CsvImport;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Перенос бумажного реестра справок в портал.
 *
 * Владелец просил одного: найти по номеру, кому выдавалась справка. Поэтому
 * переносится то, что в реестре есть, и **не придумывается то, чего в нём нет**
 * — ни даты выдачи, ни курса, ни сроков обучения. Пустая графа честнее
 * подставленной из сегодняшней карточки: на бумаге стоял тот курс, что был
 * тогда.
 *
 * Загрузка повторяемая: номер, который уже лежит в базе, пропускается. Это не
 * удобство, а условие — реестр возят по частям и перезапускают.
 *
 * **Отказ вместо предупреждения** в одном случае: если номер из бумаги уже
 * занят справкой, **выданной порталом**. Тогда одно число значится за двумя
 * документами, и разбирать это должен человек, а не загрузчик.
 */
class ImportCertificateRegister extends Command
{
    protected $signature = 'certificates:import-register
        {file : CSV из книги реестра: №, ФИО студента, Дата рождения, Специальность, Приказ, Дата приказа, Справка 1, Справка 2}
        {--dry-run : Ничего не писать, только показать, что получится}
        {--skip-unreadable : Пропустить строки с нечитаемой датой рождения, оставив дыру в нумерации}';

    protected $description = 'Перенести бумажный реестр справок в портал: номер, кому выдана, приказ о зачислении';

    public function handle(): int
    {
        $path = (string) $this->argument('file');

        if (! is_readable($path)) {
            $this->error(sprintf('Файл %s не читается.', $path));

            return self::FAILURE;
        }

        $students = $this->studentIndex();
        $existing = StudentCertificate::query()->pluck('source', 'number')->all();

        $planned = [];
        $unreadable = [];
        $skipped = 0;
        $collisions = [];
        $matched = 0;
        $unmatched = 0;
        $ambiguous = 0;
        $lines = 0;

        foreach (CsvImport::rows($path) as $line => $row) {
            $lines++;

            $name = trim((string) ($row['ФИО студента'] ?? ''));
            $birth = $this->date($row['Дата рождения'] ?? '');

            if ($name === '') {
                continue;
            }

            if ($birth === null) {
                // Дата рождения — часть ключа, по которому строка находит
                // студента, и обязательное поле реестра. Пустой её быть не
                // должно: в книге она стоит у всех 591 строки. Значит это не
                // «нет данных», а испорченная ячейка, и чинит её человек.
                $unreadable[] = sprintf('строка %d: «%s» — дата рождения «%s»', $line, $name, trim((string) ($row['Дата рождения'] ?? '')));

                continue;
            }

            $key = $this->key($name, $birth);
            $found = $students[$key] ?? [];

            if (count($found) === 1) {
                $studentId = $found[0];
                $matched++;
            } else {
                $studentId = null;
                count($found) > 1 ? $ambiguous++ : $unmatched++;
            }

            foreach (['Справка 1', 'Справка 2'] as $column) {
                $number = trim((string) ($row[$column] ?? ''));

                if (! preg_match('/^\d+$/', $number)) {
                    continue;
                }

                $number = (int) $number;

                if (isset($existing[$number])) {
                    if ($existing[$number] === StudentCertificate::SOURCE_PORTAL) {
                        $collisions[] = $number;
                    } else {
                        $skipped++;
                    }

                    continue;
                }

                $planned[$number] = [
                    'source' => StudentCertificate::SOURCE_PAPER,
                    'student_id' => $studentId,
                    'number' => $number,
                    // Даты выдачи в реестре нет вовсе: «Дата приказа» — это дата
                    // приказа о зачислении, «Дата получения» пуста у всех строк.
                    'issued_on' => null,
                    'full_name' => $name,
                    'birth_date' => $birth,
                    'specialty' => trim((string) ($row['Специальность'] ?? '')) ?: null,
                    'enrollment_order_number' => trim((string) ($row['Приказ'] ?? '')),
                    'enrollment_order_date' => $this->date($row['Дата приказа'] ?? ''),
                    'received_on' => $this->date($row['Дата получения'] ?? ''),
                    'note' => 'Перенесено из книги реестра справок колледжа.',
                ];
            }
        }

        if ($unreadable !== [] && $this->option('skip-unreadable')) {
            // Решение берёт человек, а не загрузчик: он увидел список и
            // согласился на дыру. Номера этих строк в реестр не попадут, и
            // помнить об этом придётся ему.
            $this->warn(sprintf(
                "Пропущено строк с нечитаемой датой рождения: %d. В нумерации останется дыра.\n%s",
                count($unreadable),
                implode("\n", array_slice($unreadable, 0, 12)),
            ));

            $unreadable = [];
        }

        if ($unreadable !== []) {
            // Отказ на весь файл, а не пропуск строк: пропущенная строка — это
            // пропущенный номер, а нумерация реестра сплошная. Дыру потом никто
            // не найдёт, а испорченных ячеек в книге всего несколько.
            $this->error(sprintf(
                "ОТКАЗ: %d строк с нечитаемой датой рождения. Реестр загружается целиком, иначе в нумерации появится дыра.\n%s",
                count($unreadable),
                implode("\n", array_slice($unreadable, 0, 12)),
            ));

            return self::FAILURE;
        }

        if ($collisions !== []) {
            sort($collisions);
            $this->error(sprintf(
                "ОТКАЗ: %d номеров из бумаги уже заняты справками, выданными порталом (%s%s).\n".
                'Один номер за двумя документами разбирает человек, а не загрузчик.',
                count($collisions),
                implode(', ', array_slice($collisions, 0, 10)),
                count($collisions) > 10 ? ' и другие' : '',
            ));

            return self::FAILURE;
        }

        $this->line(sprintf('Строк в файле: %d', $lines));
        $this->line(sprintf('Справок к переносу: %d', count($planned)));
        $this->line(sprintf('Уже перенесено раньше, пропущено: %d', $skipped));
        $this->line(sprintf('Студент найден однозначно: %d строк', $matched));
        $this->line(sprintf('Студент не найден, строка пойдёт без него: %d', $unmatched));
        $this->line(sprintf('Совпало несколько студентов, строка пойдёт без него: %d', $ambiguous));

        if ($this->option('dry-run')) {
            $this->info('Холостой проход: ничего не записано.');

            return self::SUCCESS;
        }

        if ($planned === []) {
            $this->info('Переносить нечего.');

            return self::SUCCESS;
        }

        ksort($planned);
        $now = now();

        DB::transaction(function () use ($planned, $now): void {
            foreach (array_chunk($planned, 200, true) as $chunk) {
                StudentCertificate::insert(array_map(
                    fn (array $row): array => $row + ['created_at' => $now, 'updated_at' => $now],
                    array_values($chunk),
                ));
            }
        });

        $this->info(sprintf(
            'Перенесено справок: %d. Номера с %d по %d.',
            count($planned),
            (int) array_key_first($planned),
            (int) array_key_last($planned),
        ));

        return self::SUCCESS;
    }

    /**
     * Студенты по ключу «ФИО + дата рождения».
     *
     * Ключ слабее, чем кажется: в реестре встречается один и тот же человек
     * дважды, а на стенде — однофамильцы. Поэтому под ключом список, и строка
     * идёт без студента, если под ним не ровно один.
     *
     * @return array<string, list<int>>
     */
    private function studentIndex(): array
    {
        $index = [];

        Student::query()
            ->select(['id', 'last_name', 'first_name', 'middle_name', 'birth_date'])
            ->whereNull('deleted_at')
            ->cursor()
            ->each(function (Student $student) use (&$index): void {
                $name = implode(' ', array_filter([$student->last_name, $student->first_name, $student->middle_name]));
                $key = $this->key($name, $student->birth_date?->toDateString());
                $index[$key][] = (int) $student->id;
            });

        return $index;
    }

    /**
     * Ключ сопоставления.
     *
     * «ё» приводится к «е»: на живом файле это дало ровно одну лишнюю строку из
     * 591 — мало, но проверять дешевле, чем спорить.
     */
    private function key(string $name, ?string $birthDate): string
    {
        $name = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)));
        $name = str_replace('ё', 'е', $name);

        return $name.'|'.(string) $birthDate;
    }

    /** Дата из реестра: там встречаются и «17.08.2026», и «16.08.24». */
    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        foreach (['d.m.Y', 'd.m.y', 'Y-m-d'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
            } catch (\Throwable) {
                continue;
            }

            if ($date !== false && $date->format($format) === $value) {
                return $date->toDateString();
            }
        }

        return null;
    }
}
