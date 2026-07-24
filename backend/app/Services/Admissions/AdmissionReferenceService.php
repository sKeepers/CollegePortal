<?php

namespace App\Services\Admissions;

use App\Models\Admissions\AdmissionReferenceCatalog;
use App\Repositories\Admissions\AdmissionReferenceRepository;
use App\Support\Admissions\AdmissionReferenceCatalogs;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Сервис read-only контракта справочников приемной комиссии.
 */
class AdmissionReferenceService
{
    /**
     * Подключает repository без состояния, чтобы сервис оставался детерминированным.
     */
    public function __construct(private readonly AdmissionReferenceRepository $references)
    {
    }

    /**
     * Возвращает все разрешенные коды справочников приемной комиссии.
     *
     * @return array<int, string>
     */
    public function allowedCodes(): array
    {
        return AdmissionReferenceCatalogs::codes();
    }

    /**
     * Возвращает справочники приемной комиссии для read-only API.
     *
     * @param array<int, string> $codes
     * @return Collection<int, AdmissionReferenceCatalog>
     */
    public function catalogs(array $codes = [], bool $activeOnly = true): Collection
    {
        $normalized = $this->normalizeCodes($codes);

        return $this->references->catalogs($normalized, $activeOnly);
    }

    /**
     * Возвращает один справочник и сообщает 404, если код не относится к приемной комиссии.
     */
    public function catalog(string $code, bool $activeOnly = true): AdmissionReferenceCatalog
    {
        $normalized = $this->normalizeCodes([$code])[0] ?? $code;
        $catalog = $this->references->catalog($normalized, $activeOnly);

        abort_if($catalog === null, 404, 'Справочник приемной комиссии не найден.');

        return $catalog;
    }

    /**
     * Нормализует и валидирует список кодов справочников из HTTP-запроса.
     *
     * @param array<int, string> $codes
     * @return array<int, string>
     */
    public function normalizeCodes(array $codes): array
    {
        $normalized = collect($codes)
            ->flatMap(fn (string $code): array => explode(',', $code))
            ->map(fn (string $code): string => trim($code))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $unknown = array_values(array_diff($normalized, $this->allowedCodes()));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'catalogs' => 'Неизвестные справочники приемной комиссии: '.implode(', ', Arr::take($unknown, 5)),
            ]);
        }

        return $normalized;
    }
}
