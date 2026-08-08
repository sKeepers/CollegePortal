<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScanAccessPassRequest;
use App\Http\Resources\AccessEventResource;
use App\Models\AccessEvent;
use App\Models\DigitalIdentity;
use App\Services\AccessPointResolver;
use App\Services\QrSvgService;
use App\Services\SettingService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;

class AccessGateController extends Controller
{
    public function scan(ScanAccessPassRequest $request, QrSvgService $qrSvgService): AccessEventResource
    {
        $validated = $request->validated();
        $token = $qrSvgService->normalizeScannedToken($validated['token']);
        $identity = $qrSvgService->isDynamicPayload($token)
            ? $qrSvgService->resolveScannedIdentity($token)
            : null;

        if ($identity !== null && ! $this->claimDynamicPayload($token)) {
            return $this->createEvent($validated, $identity, AccessEvent::RESULT_DENIED, 'QR-код уже использован. Покажите обновленный код.');
        }

        if ($identity !== null) {
            $recentEvent = $this->recentEvent($identity);
            if ($recentEvent !== null) {
                $recentEvent->setAttribute('duplicate_ignored', true);

                return new AccessEventResource($recentEvent->load('digitalIdentity'));
            }
        }

        [$result, $reason] = $this->scanResult($identity);

        return $this->createEvent($validated, $identity, $result, $reason);
    }

    private function createEvent(array $validated, ?DigitalIdentity $identity, string $result, ?string $reason): AccessEventResource
    {
        $event = AccessEvent::create([
            'digital_identity_id' => $identity?->id,
            'access_point_id' => app(AccessPointResolver::class)->resolve($validated['access_point'] ?? null)?->id,
            'entity_type' => $identity?->entity_type,
            'entity_id' => $identity?->entity_id,
            'direction' => $identity ? $this->nextDirection($identity) : AccessEvent::DIRECTION_IN,
            'event_time' => now(),
            'access_point' => $validated['access_point'] ?? null,
            'device_name' => $validated['device_name'] ?? null,
            'result' => $result,
            'reason' => $reason,
        ]);

        return new AccessEventResource($event->fresh('digitalIdentity'));
    }

    private function claimDynamicPayload(string $token): bool
    {
        return Cache::add(
            'access:dynamic-qr:'.hash('sha256', $token),
            true,
            now()->addSeconds(QrSvgService::DYNAMIC_TTL_SECONDS),
        );
    }

    public function events(): AnonymousResourceCollection
    {
        $events = AccessEvent::query()
            ->with('digitalIdentity')
            ->orderByDesc('event_time')
            ->limit(50)
            ->get();

        return AccessEventResource::collection($events);
    }

    private function recentEvent(DigitalIdentity $identity): ?AccessEvent
    {
        return AccessEvent::query()
            ->where('digital_identity_id', $identity->id)
            ->where('event_time', '>=', now()->subSeconds((int) SettingService::value('identity', 'duplicate_scan_window_seconds', 2)))
            ->orderByDesc('event_time')
            ->first();
    }

    private function nextDirection(DigitalIdentity $identity): string
    {
        $lastEvent = AccessEvent::query()
            ->where('digital_identity_id', $identity->id)
            ->orderByDesc('event_time')
            ->first();

        return $lastEvent?->direction === AccessEvent::DIRECTION_IN
            ? AccessEvent::DIRECTION_OUT
            : AccessEvent::DIRECTION_IN;
    }

    private function scanResult(?DigitalIdentity $identity): array
    {
        if ($identity === null) {
            return [AccessEvent::RESULT_DENIED, 'Пропуск не найден.'];
        }

        if ($identity->status === DigitalIdentity::STATUS_REVOKED || $identity->revoked_at !== null) {
            return [AccessEvent::RESULT_DENIED, 'Пропуск отозван.'];
        }

        if ($identity->status === DigitalIdentity::STATUS_SUSPENDED) {
            return [AccessEvent::RESULT_DENIED, 'Пропуск приостановлен.'];
        }

        if ($identity->status === DigitalIdentity::STATUS_EXPIRED || $identity->isExpired()) {
            return [AccessEvent::RESULT_DENIED, 'Срок действия пропуска истек.'];
        }

        if ($identity->status !== DigitalIdentity::STATUS_ACTIVE) {
            return [AccessEvent::RESULT_DENIED, 'Статус пропуска не разрешает проход.'];
        }

        return [AccessEvent::RESULT_ALLOWED, null];
    }
}
