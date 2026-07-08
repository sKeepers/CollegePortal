<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReferenceCatalogResource;
use App\Models\ReferenceCatalog;
use App\Services\AuditLogService;
use App\Services\ReferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ReferenceCatalogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $catalogs = ReferenceCatalog::query()
            ->withCount([
                'items',
                'items as active_items_count' => fn ($query) => $query->where('is_active', true),
            ])
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        return ReferenceCatalogResource::collection($catalogs);
    }

    public function store(Request $request): ReferenceCatalogResource
    {
        $catalog = ReferenceCatalog::create($this->validated($request));
        ReferenceService::forgetCatalog($catalog);
        AuditLogService::log('reference_data', 'create_catalog', $catalog, null, $catalog->toArray(), $request);

        return new ReferenceCatalogResource($catalog->loadCount('items'));
    }

    public function update(Request $request, ReferenceCatalog $catalog): ReferenceCatalogResource
    {
        $old = $catalog->getAttributes();
        $data = $this->validated($request, $catalog);

        if ($catalog->is_system) {
            unset($data['code'], $data['is_system']);
        }

        $catalog->update($data);
        ReferenceService::forgetCatalog($catalog);
        AuditLogService::log('reference_data', 'update_catalog', $catalog, $old, $catalog->fresh()->getAttributes(), $request);

        return new ReferenceCatalogResource($catalog->refresh()->loadCount('items'));
    }

    public function destroy(ReferenceCatalog $catalog): JsonResponse
    {
        if ($catalog->is_system) {
            return response()->json(['message' => 'Системный справочник нельзя удалить.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($catalog->items()->exists()) {
            return response()->json(['message' => 'Нельзя удалить справочник, пока в нем есть элементы.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $old = $catalog->getAttributes();
        ReferenceService::forgetCatalog($catalog);
        $catalog->delete();
        AuditLogService::log('reference_data', 'delete_catalog', ['type' => 'ReferenceCatalog', 'id' => $old['id'] ?? null], $old, null, request());

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    private function validated(Request $request, ?ReferenceCatalog $catalog = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_\-]+$/', Rule::unique('reference_catalogs', 'code')->ignore($catalog?->id)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_system' => ['sometimes', 'boolean'],
        ]);
    }
}
