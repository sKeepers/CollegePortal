<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReferenceItemResource;
use App\Models\ReferenceCatalog;
use App\Models\ReferenceItem;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ReferenceItemController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $items = ReferenceItem::query()
            ->with('catalog')
            ->when($request->integer('catalog_id'), fn ($query, int $catalogId) => $query->where('catalog_id', $catalogId))
            ->when($request->string('catalog_code')->toString(), function ($query, string $code): void {
                $query->whereHas('catalog', fn ($catalogQuery) => $catalogQuery->where('code', $code));
            })
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ReferenceItemResource::collection($items);
    }

    public function store(Request $request): ReferenceItemResource
    {
        $item = ReferenceItem::create($this->validated($request));
        AuditLogService::log('reference_data', 'create_item', $item, null, $item->toArray(), $request);

        return new ReferenceItemResource($item->load('catalog'));
    }

    public function update(Request $request, ReferenceItem $item): ReferenceItemResource
    {
        $old = $item->getAttributes();
        $data = $this->validated($request, $item);

        if ($item->isSystem()) {
            unset($data['catalog_id'], $data['code'], $data['metadata']);
        }

        $item->update($data);
        AuditLogService::log('reference_data', 'update_item', $item, $old, $item->fresh()->getAttributes(), $request);

        return new ReferenceItemResource($item->refresh()->load('catalog'));
    }

    public function destroy(ReferenceItem $item): JsonResponse
    {
        if ($item->isSystem() || $item->catalog?->is_system) {
            return response()->json(['message' => 'Системный элемент справочника нельзя удалить. Используйте деактивацию.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $old = $item->getAttributes();
        $item->delete();
        AuditLogService::log('reference_data', 'delete_item', ['type' => 'ReferenceItem', 'id' => $old['id'] ?? null], $old, null, request());

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    private function validated(Request $request, ?ReferenceItem $item = null): array
    {
        $catalogId = (int) ($request->input('catalog_id') ?? $item?->catalog_id);

        return $request->validate([
            'catalog_id' => ['required', 'integer', 'exists:reference_catalogs,id'],
            'code' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_\-]+$/', Rule::unique('reference_items', 'code')->where(fn ($query) => $query->where('catalog_id', $catalogId))->ignore($item?->id)],
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['sometimes', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ]);
    }
}
