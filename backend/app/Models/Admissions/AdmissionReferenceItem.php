<?php

namespace App\Models\Admissions;

use App\Models\ReferenceItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * Eloquent-модель элемента справочника приемной комиссии.
 */
class AdmissionReferenceItem extends ReferenceItem
{
    protected $table = 'reference_items';

    /**
     * Ограничивает элементы справочниками приемной комиссии.
     */
    public function scopeAdmissions(Builder $query): Builder
    {
        return $query->whereHas(
            'catalog',
            fn (Builder $catalogQuery): Builder => $catalogQuery->whereIn('code', \App\Support\Admissions\AdmissionReferenceCatalogs::codes())
        );
    }

    /**
     * Возвращает только активные элементы для пользовательских форм.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
