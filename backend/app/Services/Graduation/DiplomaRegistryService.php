<?php

namespace App\Services\Graduation;

use App\Models\Diploma;
use App\Models\DiplomaBlank;
use Illuminate\Support\Collection;

/**
 * Книга регистрации выданных дипломов.
 *
 * Это не отчёт и не выборка «для удобства»: книга ведётся по закону, её
 * подписывает получатель, и по ней потом отвечают на запросы о подлинности
 * диплома. Поэтому строка книги собирается из того, что действительно выдано, а
 * не из того, что заведено.
 *
 * Приложение приходит из учёта бланков, а не из `diploma_supplements`: у
 * приложения свой бланк со своим номером, и в книге стоит номер **бланка**.
 * Если бланк приложения за выпускником не закреплён, графа остаётся пустой —
 * пустая графа честнее подставленного номера диплома.
 *
 * Диплом без регистрационного номера в книгу всё равно попадает: пропуск в
 * нумерации виден сразу, а спрятанная строка — нет.
 */
class DiplomaRegistryService
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $year = null): Collection
    {
        $diplomas = Diploma::query()
            // Скобки обязательны: без них `orWhere` разложил бы любое условие,
            // добавленное сюда позже, и в книгу поехало бы лишнее.
            ->where(fn ($query) => $query->whereNotNull('issue_date')->orWhere('status', 'issued'))
            ->with([
                'graduate.student',
                'graduate.person',
                'graduate.specialty',
                'graduate.educationProgram',
                'graduate.group',
            ])
            ->get()
            ->filter(fn (Diploma $diploma): bool => $diploma->graduate !== null)
            ->when($year !== null, fn (Collection $items): Collection => $items->filter(
                fn (Diploma $diploma): bool => (int) $diploma->graduate->graduation_year === $year,
            ));

        $supplements = $this->supplementNumbers($diplomas->pluck('graduate_id')->all());

        return $diplomas
            ->map(fn (Diploma $diploma): array => [
                'diploma_id' => $diploma->id,
                'registration_number' => $diploma->registration_number,
                'issue_date' => $diploma->issue_date?->toDateString(),
                'full_name' => $this->fullName($diploma),
                'graduation_year' => (int) $diploma->graduate->graduation_year,
                'group' => $diploma->graduate->group?->name,
                'specialty' => $diploma->graduate->specialty?->name
                    ?? $diploma->graduate->educationProgram?->name,
                'qualification' => $diploma->qualification ?: $diploma->graduate->qualification,
                'diploma_blank' => trim((string) $diploma->series.' '.(string) $diploma->number) ?: null,
                'supplement_blank' => $supplements[$diploma->graduate_id] ?? null,
                'gia_decision' => $diploma->gia_decision,
                'status' => $diploma->status,
            ])
            ->sortBy(fn (array $row): string => $this->orderKey($row))
            ->values();
    }

    /**
     * Годы, за которые в книге есть строки.
     *
     * @return list<int>
     */
    public function years(): array
    {
        return Diploma::query()
            ->where(fn ($query) => $query->whereNotNull('issue_date')->orWhere('status', 'issued'))
            ->with('graduate:id,graduation_year')
            ->get()
            ->map(fn (Diploma $diploma): ?int => $diploma->graduate?->graduation_year)
            ->filter()
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }

    /**
     * Номера бланков приложений по выпускникам.
     *
     * @param  list<int>  $graduateIds
     * @return array<int, string>
     */
    private function supplementNumbers(array $graduateIds): array
    {
        if ($graduateIds === []) {
            return [];
        }

        return DiplomaBlank::query()
            ->where('kind', DiplomaBlank::KIND_SUPPLEMENT)
            ->whereIn('graduate_id', $graduateIds)
            ->whereIn('status', [DiplomaBlank::STATUS_ASSIGNED, DiplomaBlank::STATUS_ISSUED])
            ->get()
            ->mapWithKeys(fn (DiplomaBlank $blank): array => [$blank->graduate_id => $blank->label()])
            ->all();
    }

    private function fullName(Diploma $diploma): string
    {
        $graduate = $diploma->graduate;
        $source = $graduate->student ?? $graduate->person;

        if ($source === null) {
            return '—';
        }

        return trim(implode(' ', array_filter([
            $source->last_name,
            $source->first_name,
            $source->middle_name,
        ])));
    }

    /**
     * Ключ порядка: год выпуска, потом регистрационный номер.
     *
     * Номер бывает и «12», и «12-а», поэтому сравнивается не строкой целиком:
     * числовая часть дополняется нулями до одной ширины, а хвост идёт следом.
     * Иначе «10» встанет между «1» и «2», и книга перестанет читаться.
     * Диплом без номера уходит в конец своего года — пропуск в нумерации виден
     * сразу, а спрятанная строка нет.
     */
    private function orderKey(array $row): string
    {
        $value = trim((string) $row['registration_number']);

        if ($value === '') {
            return sprintf('%04d|9999999999|', $row['graduation_year']);
        }

        preg_match('/^(\d*)(.*)$/u', $value, $match);

        return sprintf(
            '%04d|%010d|%s',
            $row['graduation_year'],
            $match[1] === '' ? 9999999999 : (int) $match[1],
            $match[2],
        );
    }
}
