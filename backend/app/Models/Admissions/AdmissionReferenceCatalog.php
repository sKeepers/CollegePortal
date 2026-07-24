<?php

namespace App\Models\Admissions;

use App\Models\ReferenceCatalog;
use Illuminate\Database\Eloquent\Builder;

/**
 * Eloquent-модель системного каталога справочников приемной комиссии.
 */
class AdmissionReferenceCatalog extends ReferenceCatalog
{
    /**
     * Ограничивает выборку каталогами, относящимися к приемной комиссии.
     */
    public function scopeAdmissions(Builder $query): Builder
    {
        return $query->whereIn('code', \App\Support\Admissions\AdmissionReferenceCatalogs::codes());
    }
}
