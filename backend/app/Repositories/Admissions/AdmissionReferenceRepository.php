<?php

namespace App\Repositories\Admissions;

use App\Models\Admissions\AdmissionReferenceCatalog;
use App\Models\Admissions\AdmissionReferenceItem;
use Illuminate\Database\Eloquent\Collection;

/**
 * Repository read-only доступа к справочникам приемной комиссии.
 */
class AdmissionReferenceRepository
{
    /**
     * Возвращает каталоги приемной комиссии с загруженными элементами.
     *
     * @param array<int, string> $codes
     * @return Collection<int, AdmissionReferenceCatalog>
     */
    public function catalogs(array $codes = [], bool $activeOnly = true): Collection
    {
        return AdmissionReferenceCatalog::query()
            ->admissions()
            ->when($codes !== [], fn ($query) => $query->whereIn('code', $codes))
            ->with(['items' => function ($query) use ($activeOnly): void {
                $query
                    ->when($activeOnly, fn ($itemQuery) => $itemQuery->where('is_active', true))
                    ->orderBy('sort_order')
                    ->orderBy('name');
            }])
            ->orderBy('name')
            ->get();
    }

    /**
     * Возвращает один admissions-справочник по коду.
     */
    public function catalog(string $code, bool $activeOnly = true): ?AdmissionReferenceCatalog
    {
        return $this->catalogs([$code], $activeOnly)->first();
    }

    /**
     * Возвращает плоскую выборку элементов одного admissions-справочника.
     *
     * @return Collection<int, AdmissionReferenceItem>
     */
    public function items(string $code, bool $activeOnly = true): Collection
    {
        return AdmissionReferenceItem::query()
            ->admissions()
            ->whereHas('catalog', fn ($query) => $query->where('code', $code))
            ->when($activeOnly, fn ($query) => $query->active())
            ->with('catalog')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }
}
