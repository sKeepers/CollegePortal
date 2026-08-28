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

    public function import(array $data, string $mode): string
    {
        $existing = $this->findExisting($data);
        if ($mode === self::MODE_UPDATE) {
            if (!$existing) { return 'skipped'; }
            $existing->update($this->payload($data, true));
            return 'updated';
        }
        if ($existing) {
            if ($mode === self::MODE_SKIP_DUPLICATES) { return 'skipped'; }
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
