<?php

namespace App\Services;

use App\Models\ReferenceCatalog;
use App\Models\ReferenceItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ReferenceService
{
    private const CACHE_PREFIX = 'reference.catalog.';

    /**
     * @return Collection<int, ReferenceItem>
     */
    public static function catalog(string $code, bool $activeOnly = true): Collection
    {
        $cacheKey = self::cacheKey($code, $activeOnly);

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($code, $activeOnly): Collection {
            $catalog = ReferenceCatalog::query()->where('code', $code)->first();

            if ($catalog === null) {
                return collect();
            }

            return ReferenceItem::query()
                ->where('catalog_id', $catalog->id)
                ->when($activeOnly, fn ($query) => $query->where('is_active', true))
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();
        });
    }

    /**
     * @return array<int, array{label: string, value: string, code: string, id: int|null, metadata: array|null}>
     */
    public static function options(string $code, bool $activeOnly = true, string $valueField = 'code'): array
    {
        return self::catalog($code, $activeOnly)
            ->map(fn (ReferenceItem $item): array => [
                'label' => $item->name,
                'value' => $valueField === 'name' ? $item->name : $item->code,
                'code' => $item->code,
                'id' => $item->id,
                'metadata' => $item->metadata,
            ])
            ->values()
            ->all();
    }

    public static function forget(string $code): void
    {
        Cache::forget(self::cacheKey($code, true));
        Cache::forget(self::cacheKey($code, false));
    }

    public static function forgetCatalog(?ReferenceCatalog $catalog): void
    {
        if ($catalog !== null) {
            self::forget($catalog->code);
        }
    }

    private static function cacheKey(string $code, bool $activeOnly): string
    {
        return self::CACHE_PREFIX.$code.'.'.($activeOnly ? 'active' : 'all');
    }
}
