<?php

namespace App\Http\Controllers\Api\Admissions;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admissions\AdmissionReferenceCatalogResource;
use App\Services\Admissions\AdmissionReferenceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only API контроллер справочников приемной комиссии.
 */
class AdmissionReferenceController extends Controller
{
    /**
     * Получает сервис справочников через контейнер Laravel.
     */
    public function __construct(private readonly AdmissionReferenceService $references)
    {
    }

    /**
     * Возвращает набор справочников приемной комиссии.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $codes = $request->input('catalogs', []);
        $codes = is_array($codes) ? $codes : [$codes];

        return AdmissionReferenceCatalogResource::collection(
            $this->references->catalogs($codes, $this->activeOnly($request))
        );
    }

    /**
     * Возвращает один справочник приемной комиссии по коду.
     */
    public function show(Request $request, string $catalog): AdmissionReferenceCatalogResource
    {
        return new AdmissionReferenceCatalogResource(
            $this->references->catalog($catalog, $this->activeOnly($request))
        );
    }

    /**
     * Определяет, нужно ли скрывать неактивные элементы справочников.
     */
    private function activeOnly(Request $request): bool
    {
        return ! $request->has('active_only') || $request->boolean('active_only');
    }
}
