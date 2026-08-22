<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScanAccessPassRequest;
use App\Http\Resources\AccessEventResource;
use App\Models\AccessEvent;
use App\Models\DigitalIdentity;
use App\Services\AccessCardResolver;
use App\Services\AccessPointResolver;
use App\Services\QrSvgService;
use App\Services\SettingService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;

class AccessGateController extends Controller
{
    /**
     * Проход по QR или по RFID-карте — одно окно на оба способа.
     *
     * Различать их по виду строки достаточно и надёжно: динамический QR всегда
     * начинается с `CP2:`, всё остальное — номер карты. Оба считывателя
     * работают как клавиатура и пишут в одно поле, так что на посту охраны это
     * один и тот же ввод.
     */
    public function scan(ScanAccessPassRequest $request, QrSvgService $qrSvgService, AccessCardResolver $cards): AccessEventResource
    {
        $validated = $request->validated();
        $token = $qrSvgService->normalizeScannedToken($validated['token']);

        return $qrSvgService->isDynamicPayload($token)
            ? $this->scanQr($validated, $token, $qrSvgService)
            : $this->scanCard($validated, $token, $cards);
    }

    private function scanQr(array $validated, string $token, QrSvgService $qrSvgService): AccessEventResource
    {
        $identity = $qrSvgService->resolveScannedIdentity($token);

        if ($identity !== null && ! $this->claimDynamicPayload($token)) {
            return new AccessEventResource($this->createEvent($validated, $identity, AccessEvent::RESULT_DENIED, 'QR-код уже использован. Покажите обновленный код.'));
        }

        if ($identity !== null) {
            $recentEvent = $this->recentEvent($identity);
            if ($recentEvent !== null) {
                $recentEvent->setAttribute('duplicate_ignored', true);

                return new AccessEventResource($recentEvent->load('digitalIdentity'));
            }
        }

        [$result, $reason] = $this->scanResult($identity);

        return new AccessEventResource($this->createEvent($validated, $identity, $result, $reason));
    }

    /**
     * Проход по карте.
     *
     * Главное отличие от QR: **у карты нет одноразовости**. Динамический код
     * живёт 30 секунд и гасится при первом предъявлении, поэтому окно защиты от
     * дублей для него не срабатывает никогда. Карта же лежит на считывателе, и
     * тот отдаёт номер снова и снова — без окна одно прикладывание записалось бы
     * входом, следующим чтением выходом, и человек «вышел» бы, не сходя с места.
     *
     * Окно ведём по номеру карты, а не по пропуску: тогда оно работает и для
     * отказов, у которых пропуска нет вовсе — забытая на считывателе утерянная
     * карта иначе завалила бы журнал отказами.
     */
    private function scanCard(array $validated, string $token, AccessCardResolver $cards): AccessEventResource
    {
        $resolved = $cards->resolve($token);
        $cacheKey = 'access:card:'.hash('sha256', $resolved['uid'] ?? $token);
        $repeatedId = Cache::get($cacheKey);

        if ($repeatedId !== null) {
            $repeated = AccessEvent::query()->find($repeatedId);

            if ($repeated !== null) {
                $repeated->setAttribute('duplicate_ignored', true);

                return new AccessEventResource($repeated->load('digitalIdentity'));
            }
        }

        $event = $this->createEvent(
            $validated,
            $resolved['identity'],
            $resolved['reason'] === null ? AccessEvent::RESULT_ALLOWED : AccessEvent::RESULT_DENIED,
            $resolved['reason'],
        );

        Cache::put($cacheKey, $event->id, now()->addSeconds($this->duplicateWindowSeconds()));

        return new AccessEventResource($event);
    }

    private function duplicateWindowSeconds(): int
    {
        return max(1, (int) SettingService::value('identity', 'duplicate_scan_window_seconds', 2));
    }

    private function createEvent(array $validated, ?DigitalIdentity $identity, string $result, ?string $reason): AccessEvent
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

        return $event->fresh('digitalIdentity');
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

    /**
     * Направление чередуется по состоявшимся проходам, а не по всем событиям.
     *
     * Отказ — это не проход: человек остался по ту же сторону двери. Пока
     * отказы попадали в расчёт, каждый лишний скан переставлял направление, и
     * следующий настоящий проход записывался наоборот. Ловится это легко:
     * динамический QR одноразовый, камера читает телефон в кадре несколько раз
     * подряд, второй скан даёт «QR-код уже использован» — и вошедший человек
     * при следующем проходе снова «входит», хотя выходит.
     *
     * Присутствие в здании (`AccessPresenceService`) уже считается только по
     * разрешённым событиям, так что теперь обе стороны смотрят на одно и то же.
     */
    private function nextDirection(DigitalIdentity $identity): string
    {
        $lastEvent = AccessEvent::query()
            ->where('digital_identity_id', $identity->id)
            ->where('result', AccessEvent::RESULT_ALLOWED)
            ->orderByDesc('event_time')
            ->orderByDesc('id')
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
