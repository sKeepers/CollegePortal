<?php

namespace App\Services\Import;

use Illuminate\Support\Arr;
use RuntimeException;

abstract class AbstractImportHandler implements ImportHandlerInterface
{
    public const MODE_CREATE = 'create';
    public const MODE_UPDATE = 'update';
    public const MODE_SKIP_DUPLICATES = 'skip_duplicates';

    public function prepare(array $data): array { return $data; }

    public function payload(array $data, bool $update = false): array
    {
        $payload = Arr::only($data, array_keys($this->fields()));
        foreach ($this->virtualFields() as $field) { unset($payload[$field]); }
        return array_filter($payload, fn ($value) => $update ? $value !== null : true);
    }

    /**
     * По умолчанию замечаний нет.
     *
     * Молчание здесь означает «этот загрузчик ничего не теряет по дороге», и для
     * аудиторий или сетки звонков это правда: ссылок на другие таблицы у них нет.
     * Загрузчику, который что-то разрешает по названию, метод стоит переопределить.
     */
    public function rowNotices(array $data): array
    {
        return [];
    }

    /**
     * Почему пропущена последняя строка.
     *
     * Пропуск возвращается из **двух противоположных случаев**: «не нашли по
     * ключу, обновлять нечего» и «нашли, пропускаем как дубликат». До
     * 31.08.2026 оба приходили на экран одинаково — числом «пропущено N», — и
     * различить их было нечем. На пятнадцати строках это читается как
     * «наверное, дубликаты»; в день, когда грузят расписание, аудитории двух
     * корпусов, комнаты и жильцов разом, за этим числом стоят живые пары и
     * живые комнаты, и разница между «не нашёл» и «уже есть» стоит дорого.
     *
     * Повод хранится здесь, а не возвращается из `import()`, потому что
     * `import()` объявлен в интерфейсе и переопределён девятью загрузчиками:
     * менять его вид значило бы трогать девять файлов ради одной строки.
     * Забывает повод **служба** перед каждой строкой — переопределившие
     * `import()` о нём не знают и знать не обязаны.
     */
    private ?string $skipReason = null;

    /** Строку не нашли по ключевым полям: в режиме обновления обновлять нечего. */
    protected const SKIP_NOT_FOUND = 'Строка не найдена по ключевым полям, а выбран режим обновления — обновлять нечего. Если строку нужно завести, выберите режим создания.';

    /** Такая запись уже есть, и режим велит дубликаты пропускать. */
    protected const SKIP_DUPLICATE = 'Такая запись уже есть, и выбран режим «пропускать дубликаты» — строка не менялась. Если её нужно обновить, выберите режим обновления.';

    public function lastSkipReason(): ?string
    {
        return $this->skipReason;
    }

    public function forgetSkipReason(): void
    {
        $this->skipReason = null;
    }

    /** Пропуск с названной причиной: возвращать «skipped» молча больше нельзя. */
    protected function skipped(string $reason): string
    {
        $this->skipReason = $reason;

        return 'skipped';
    }

    public function import(array $data, string $mode): string
    {
        $existing = $this->findExisting($data);
        if ($mode === self::MODE_UPDATE) {
            if (!$existing) { return $this->skipped(self::SKIP_NOT_FOUND); }
            $existing->update($this->payload($data, true));
            return 'updated';
        }
        if ($existing) {
            if ($mode === self::MODE_SKIP_DUPLICATES) { return $this->skipped(self::SKIP_DUPLICATE); }
            throw new RuntimeException('Дубликат по ключевому полю.');
        }
        $modelClass = $this->modelClass();
        $modelClass::create($this->payload($data));
        return 'created';
    }

    public function businessValidationErrors(array $data): array { return []; }
    protected function virtualFields(): array { return []; }

    protected function normalizeDate(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') { return null; }
        if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $value)) {
            [$day, $month, $year] = explode('.', $value);
            return "{$year}-{$month}-{$day}";
        }
        if (is_numeric($value) && (int) $value > 20000) { return gmdate('Y-m-d', ((int) $value - 25569) * 86400); }
        return $value;
    }

    protected function normalizeTime(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') { return null; }
        if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $value)) {
            [$hour, $minute] = array_slice(explode(':', $value), 0, 2);
            return sprintf('%02d:%02d', (int) $hour, (int) $minute);
        }
        if (is_numeric($value) && (float) $value > 0 && (float) $value < 1) {
            $minutes = (int) round(((float) $value) * 24 * 60);
            return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        }
        return $value;
    }

    protected function booleanValue($value): bool
    {
        if (is_bool($value)) { return $value; }
        return in_array(mb_strtolower(trim((string) $value)), ['1', 'true', 'да', 'активен', 'yes'], true);
    }
}
