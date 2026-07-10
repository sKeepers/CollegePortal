<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScanAccessPassRequest;
use App\Http\Resources\AccessEventResource;
use App\Models\AccessEvent;
use App\Models\DigitalIdentity;
use App\Services\QrSvgService;
use App\Services\SettingService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccessGateController extends Controller
{
    public function scan(ScanAccessPassRequest $request, QrSvgService $qrSvgService): AccessEventResource
    {
        $validated = $request->validated();
        $token = $qrSvgService->normalizeScannedToken($validated['token']);
        $identity = DigitalIdentity::query()->where('token', $token)->first();

        if ($identity !== null) {
            $recentEvent = $this->recentEvent($identity);
            if ($recentEvent !== null) {
                $recentEvent->setAttribute('duplicate_ignored', true);

                return new AccessEventResource($recentEvent->load('digitalIdentity'));
            }
        }

        $direction = $identity ? $this->nextDirection($identity) : AccessEvent::DIRECTION_IN;
        [$result, $reason] = $this->scanResult($identity);

        $event = AccessEvent::create([
            'digital_identity_id' => $identity?->id,
            'entity_type' => $identity?->entity_type,
            'entity_id' => $identity?->entity_id,
            'direction' => $direction,
            'event_time' => now(),
            'access_point' => $validated['access_point'] ?? null,
            'device_name' => $validated['device_name'] ?? null,
            'result' => $result,
            'reason' => $reason,
        ]);

        return new AccessEventResource($event->fresh('digitalIdentity'));
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
