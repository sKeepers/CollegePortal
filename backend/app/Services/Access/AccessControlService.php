<?php

namespace App\Services\Access;

use App\Models\AccessAuditEvent;
use App\Models\AccessDenial;
use App\Models\AccessDevice;
use App\Models\AccessEvent;
use App\Models\AccessPassToken;
use App\Models\AccessPoint;
use App\Models\AccessSession;
use App\Models\DigitalIdentity;
use App\Models\Person;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\QrSvgService;
use App\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccessControlService
{
    private const TOKEN_PREFIX = 'CP2:';
    private const TOKEN_VERSION = 1;
    private const TOKEN_TTL_SECONDS = 30;
    private const CLOCK_SKEW_SECONDS = 5;

    public function __construct(
        private readonly QrSvgService $qrSvgService,
        private readonly AccessAttendanceBridge $attendanceBridge,
    ) {}

    /** @return array{token:string,expires_at:string,issued_at:string,ttl_seconds:int,qr_svg:string,person:array<string,mixed>} */
    public function issueToken(User $user, array $payload, ?Request $request = null): array
    {
        $person = $this->resolvePersonForIssue($user, $payload);
        $this->authorizeIssue($user, $person);

        $issuedAt = now();
        $expiresAt = $issuedAt->copy()->addSeconds(self::TOKEN_TTL_SECONDS);
        $nonce = Str::random(32);
        $tokenPayload = [
            'v' => self::TOKEN_VERSION,
            'sub' => $person->id,
            'n' => $nonce,
            'iat' => $issuedAt->timestamp,
            'exp' => $expiresAt->timestamp,
        ];
        $token = $this->encodeToken($tokenPayload);

        $record = AccessPassToken::create([
            'person_id' => $person->id,
            'token_hash' => $this->hashToken($token),
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'nonce' => $nonce,
            'version' => self::TOKEN_VERSION,
            'device_identifier' => $payload['device_identifier'] ?? null,
        ]);

        AccessAuditEvent::create([
            'user_id' => $user->id,
            'person_id' => $person->id,
            'action' => 'token_issued',
            'request_id' => $this->requestId($request),
            'metadata' => ['token_id' => $record->id, 'expires_at' => $expiresAt->toISOString()],
        ]);

        return [
            'token' => $token,
            'issued_at' => $issuedAt->toISOString(),
            'expires_at' => $expiresAt->toISOString(),
            'ttl_seconds' => self::TOKEN_TTL_SECONDS,
            'qr_svg' => $this->qrSvgService->renderSvg($token),
            'person' => $this->personDisplay($person),
        ];
    }

    public function scan(User $operator, array $payload, ?Request $request = null): AccessEvent
    {
        $rawToken = $this->qrSvgService->normalizeScannedToken((string) $payload['token']);
        $requestId = $payload['request_id'] ?? $this->requestId($request);
        $accessPoint = $this->resolveAccessPoint($payload);
        $device = $this->resolveDevice($payload, $accessPoint);
        $tokenResult = $this->resolveToken($rawToken);
        $direction = $this->resolveDirection($tokenResult, $payload['direction'] ?? null);

        return DB::transaction(function () use ($operator, $payload, $request, $requestId, $accessPoint, $device, $tokenResult, $direction): AccessEvent {
            [$allowed, $reasonCode, $reason] = $this->decision($tokenResult, $direction);
            $person = $tokenResult['person'];
            $identity = $tokenResult['identity'];

            $event = AccessEvent::create([
                'person_id' => $person?->id,
                'access_point_id' => $accessPoint?->id,
                'device_id' => $device?->id,
                'operator_id' => $operator->id,
                'request_id' => $requestId,
                'digital_identity_id' => $identity?->id,
                'entity_type' => $tokenResult['entity_type'],
                'entity_id' => $tokenResult['entity_id'],
                'direction' => $direction,
                'event_time' => now(),
                'occurred_at' => now(),
                'access_point' => $accessPoint?->name ?? ($payload['access_point'] ?? null),
                'device_name' => $device?->name ?? ($payload['device_name'] ?? null),
                'result' => $allowed ? AccessEvent::RESULT_ALLOWED : AccessEvent::RESULT_DENIED,
                'reason_code' => $reasonCode,
                'reason' => $reason,
            ]);

            if ($allowed && $tokenResult['pass_token'] instanceof AccessPassToken) {
                $tokenResult['pass_token']->forceFill(['used_at' => now()])->save();
            }

            if ($allowed && $person !== null) {
                $this->syncSession($person, $event);
                $this->attendanceBridge->accessEventRecorded($event);
            }

            if (! $allowed) {
                AccessDenial::create([
                    'access_event_id' => $event->id,
                    'person_id' => $person?->id,
                    'reason_code' => $reasonCode,
                    'reason' => $reason,
                    'context' => ['token_type' => $tokenResult['type'], 'direction' => $direction],
                ]);
            }

            AccessAuditEvent::create([
                'user_id' => $operator->id,
                'person_id' => $person?->id,
                'access_event_id' => $event->id,
                'action' => $allowed ? 'scan_allowed' : 'scan_denied',
                'request_id' => $requestId,
                'metadata' => ['reason_code' => $reasonCode, 'direction' => $direction, 'token_type' => $tokenResult['type']],
            ]);

            AuditLogService::log('access', $allowed ? 'scan_allowed' : 'scan_denied', $event, null, [
                'person_id' => $person?->id,
                'direction' => $direction,
                'result' => $event->result,
                'reason_code' => $reasonCode,
            ], $request, $operator, $person?->id, $requestId);

            return $event->fresh(['person.primaryStudent.group', 'person.primaryTeacher.employee.primaryDepartment', 'digitalIdentity']);
        });
    }

    public function override(User $operator, AccessEvent $event, string $reason, ?Request $request = null): AccessEvent
    {
        $old = $event->getAttributes();
        $event->forceFill([
            'result' => AccessEvent::RESULT_ALLOWED,
            'reason_code' => 'operator_override',
            'reason' => $reason,
            'operator_id' => $operator->id,
        ])->save();

        AccessAuditEvent::create([
            'user_id' => $operator->id,
            'person_id' => $event->person_id,
            'access_event_id' => $event->id,
            'action' => 'override',
            'request_id' => $this->requestId($request),
            'metadata' => ['reason' => $reason],
        ]);
        AuditLogService::log('access', 'override', $event, $old, $event->fresh()->getAttributes(), $request, $operator, $event->person_id);

        return $event->fresh(['person.primaryStudent.group', 'person.primaryTeacher.employee.primaryDepartment', 'digitalIdentity']);
    }

    /** @return array<string,mixed> */
    private function resolveToken(string $token): array
    {
        if (str_starts_with($token, self::TOKEN_PREFIX)) {
            return $this->resolveDynamicToken($token);
        }

        return $this->resolveLegacyToken($token);
    }

    /** @return array<string,mixed> */
    private function resolveDynamicToken(string $token): array
    {
        $invalid = ['type' => 'dynamic', 'valid' => false, 'reason_code' => 'invalid_token', 'reason' => 'QR-токен недействителен.', 'person' => null, 'identity' => null, 'pass_token' => null, 'entity_type' => null, 'entity_id' => null];
        $body = substr($token, strlen(self::TOKEN_PREFIX));
        $parts = explode('.', $body, 2);
        if (count($parts) !== 2) {
            return $invalid;
        }

        [$payloadEncoded, $signature] = $parts;
        $expected = $this->sign($payloadEncoded);
        if (! hash_equals($expected, $signature)) {
            return array_merge($invalid, ['reason_code' => 'invalid_signature', 'reason' => 'Подпись QR-токена не прошла проверку.']);
        }

        $payload = json_decode($this->base64UrlDecode($payloadEncoded), true);
        if (! is_array($payload) || ($payload['v'] ?? null) !== self::TOKEN_VERSION || ! isset($payload['sub'], $payload['n'], $payload['iat'], $payload['exp'])) {
            return $invalid;
        }

        $record = AccessPassToken::query()->where('token_hash', $this->hashToken($token))->first();
        $person = Person::with(['primaryStudent.group', 'primaryTeacher.employee.primaryDepartment'])->find((int) $payload['sub']);
        $now = now()->timestamp;

        if (! $record || ! $person || $record->person_id !== $person->id || ! hash_equals($record->nonce, (string) $payload['n'])) {
            return $invalid;
        }

        if ((int) $payload['iat'] > $now + self::CLOCK_SKEW_SECONDS) {
            return array_merge($invalid, ['person' => $person, 'pass_token' => $record, 'reason_code' => 'clock_skew', 'reason' => 'Время QR-токена выходит за допустимое окно.']);
        }

        if ((int) $payload['exp'] + self::CLOCK_SKEW_SECONDS < $now || $record->expires_at->copy()->addSeconds(self::CLOCK_SKEW_SECONDS)->isPast()) {
            return array_merge($invalid, ['person' => $person, 'pass_token' => $record, 'reason_code' => 'expired_token', 'reason' => 'Срок действия QR истек.']);
        }

        if ($record->revoked_at !== null) {
            return array_merge($invalid, ['person' => $person, 'pass_token' => $record, 'reason_code' => 'revoked_token', 'reason' => 'QR-токен отозван.']);
        }

        if ($record->used_at !== null) {
            return array_merge($invalid, ['person' => $person, 'pass_token' => $record, 'reason_code' => 'replayed_token', 'reason' => 'QR уже был использован. Обновите пропуск.']);
        }

        return ['type' => 'dynamic', 'valid' => true, 'reason_code' => null, 'reason' => null, 'person' => $person, 'identity' => null, 'pass_token' => $record, 'entity_type' => $this->entityType($person), 'entity_id' => $this->entityId($person)];
    }

    /** @return array<string,mixed> */
    private function resolveLegacyToken(string $token): array
    {
        $identity = DigitalIdentity::query()->where('token', $token)->first();
        if (! $identity) {
            return ['type' => 'legacy', 'valid' => false, 'reason_code' => 'not_found', 'reason' => 'Пропуск не найден.', 'person' => null, 'identity' => null, 'pass_token' => null, 'entity_type' => null, 'entity_id' => null];
        }

        $person = $identity->person ?: $this->personFromIdentity($identity);
        $valid = $identity->status === DigitalIdentity::STATUS_ACTIVE && ! $identity->isExpired() && $identity->revoked_at === null;
        $reasonCode = null;
        $reason = null;

        if (! $valid) {
            [$reasonCode, $reason] = match (true) {
                $identity->status === DigitalIdentity::STATUS_REVOKED || $identity->revoked_at !== null => ['revoked_identity', 'Пропуск отозван.'],
                $identity->status === DigitalIdentity::STATUS_SUSPENDED => ['suspended_identity', 'Пропуск приостановлен.'],
                $identity->status === DigitalIdentity::STATUS_EXPIRED || $identity->isExpired() => ['expired_identity', 'Срок действия пропуска истек.'],
                default => ['inactive_identity', 'Статус пропуска не разрешает проход.'],
            };
        }

        return ['type' => 'legacy', 'valid' => $valid, 'reason_code' => $reasonCode, 'reason' => $reason, 'person' => $person, 'identity' => $identity, 'pass_token' => null, 'entity_type' => $identity->entity_type, 'entity_id' => $identity->entity_id];
    }

    private function resolvePersonForIssue(User $user, array $payload): Person
    {
        if (! empty($payload['person_id'])) {
            $person = Person::query()->find((int) $payload['person_id']);
            if ($person) {
                return $person;
            }
        }

        if (! empty($payload['entity_type']) && ! empty($payload['entity_id'])) {
            $person = match ($payload['entity_type']) {
                DigitalIdentity::ENTITY_STUDENT => Student::query()->find((int) $payload['entity_id'])?->person,
                DigitalIdentity::ENTITY_TEACHER => Teacher::query()->find((int) $payload['entity_id'])?->person,
                default => null,
            };
            if ($person) {
                return $person;
            }
        }

        $person = match ($user->person_type) {
            DigitalIdentity::ENTITY_STUDENT => Student::query()->find((int) $user->person_id)?->person,
            DigitalIdentity::ENTITY_TEACHER => Teacher::query()->find((int) $user->person_id)?->person,
            default => $user->person,
        };

        if (! $person) {
            throw ValidationException::withMessages(['person_id' => ['Текущий пользователь не связан с Person.']]);
        }

        return $person;
    }

    private function authorizeIssue(User $user, Person $person): void
    {
        if ($user->hasPermission('access.manage') || $user->hasPermission('digitalpasses.manage')) {
            return;
        }

        $ownPerson = $this->resolveUserPerson($user);
        if ($ownPerson?->id === $person->id) {
            return;
        }

        abort(403, 'Forbidden.');
    }

    private function resolveUserPerson(User $user): ?Person
    {
        return match ($user->person_type) {
            DigitalIdentity::ENTITY_STUDENT => Student::query()->find((int) $user->person_id)?->person,
            DigitalIdentity::ENTITY_TEACHER => Teacher::query()->find((int) $user->person_id)?->person,
            default => $user->person,
        };
    }

    private function decision(array $tokenResult, string $direction): array
    {
        if (! $tokenResult['valid']) {
            return [false, $tokenResult['reason_code'], $tokenResult['reason']];
        }

        $last = $this->lastAllowedEventForToken($tokenResult);
        if ($last && $last->direction === $direction) {
            return [false, 'duplicate_direction', $direction === AccessEvent::DIRECTION_ENTRY ? 'Повторный вход без выхода запрещен.' : 'Повторный выход без входа запрещен.'];
        }

        return [true, null, null];
    }

    private function resolveDirection(array $tokenResult, ?string $requested): string
    {
        $normalized = match ($requested) {
            AccessEvent::DIRECTION_EXIT, 'out' => AccessEvent::DIRECTION_EXIT,
            AccessEvent::DIRECTION_ENTRY, 'in' => AccessEvent::DIRECTION_ENTRY,
            default => null,
        };

        if ($normalized) {
            return $normalized;
        }

        $last = $this->lastAllowedEventForToken($tokenResult);
        return $last?->direction === AccessEvent::DIRECTION_ENTRY ? AccessEvent::DIRECTION_EXIT : AccessEvent::DIRECTION_ENTRY;
    }

    private function lastAllowedEventForToken(array $tokenResult): ?AccessEvent
    {
        if ($tokenResult['person'] instanceof Person) {
            return $this->lastAllowedEvent($tokenResult['person']);
        }

        if ($tokenResult['identity'] instanceof DigitalIdentity) {
            return AccessEvent::query()
                ->where('digital_identity_id', $tokenResult['identity']->id)
                ->where('result', AccessEvent::RESULT_ALLOWED)
                ->orderByDesc('event_time')
                ->first();
        }

        return null;
    }

    private function lastAllowedEvent(?Person $person): ?AccessEvent
    {
        if (! $person) {
            return null;
        }

        return AccessEvent::query()
            ->where('person_id', $person->id)
            ->where('result', AccessEvent::RESULT_ALLOWED)
            ->orderByDesc('event_time')
            ->first();
    }

    private function syncSession(Person $person, AccessEvent $event): void
    {
        if ($event->direction === AccessEvent::DIRECTION_ENTRY) {
            AccessSession::create(['person_id' => $person->id, 'entry_event_id' => $event->id, 'started_at' => $event->event_time, 'status' => 'open']);
            return;
        }

        $session = AccessSession::query()->where('person_id', $person->id)->where('status', 'open')->latest('started_at')->first();
        $session?->update(['exit_event_id' => $event->id, 'ended_at' => $event->event_time, 'status' => 'closed']);
    }

    private function resolveAccessPoint(array $payload): ?AccessPoint
    {
        if (! empty($payload['access_point_id'])) {
            return AccessPoint::query()->find((int) $payload['access_point_id']);
        }

        $name = trim((string) ($payload['access_point'] ?? 'Главный вход'));
        if ($name === '') {
            return null;
        }

        return AccessPoint::query()->firstOrCreate(['name' => $name], ['location' => $name, 'direction_mode' => 'both', 'active' => true]);
    }

    private function resolveDevice(array $payload, ?AccessPoint $point): ?AccessDevice
    {
        if (! empty($payload['device_id'])) {
            $device = AccessDevice::query()->find((int) $payload['device_id']);
            $device?->forceFill(['last_seen_at' => now()])->save();
            return $device;
        }

        $identifier = $payload['device_identifier'] ?? null;
        $name = trim((string) ($payload['device_name'] ?? 'HID QR Scanner'));
        if (! $identifier && $name === '') {
            return null;
        }

        $attributes = $identifier ? ['identifier' => $identifier] : ['name' => $name, 'access_point_id' => $point?->id];
        $device = AccessDevice::query()->firstOrCreate($attributes, [
            'access_point_id' => $point?->id,
            'type' => $payload['device_type'] ?? AccessDevice::TYPE_HID_SCANNER,
            'name' => $name ?: 'Устройство проходной',
            'active' => true,
        ]);
        $device->forceFill(['last_seen_at' => now(), 'access_point_id' => $device->access_point_id ?: $point?->id])->save();

        return $device;
    }

    private function personFromIdentity(DigitalIdentity $identity): ?Person
    {
        return match ($identity->entity_type) {
            DigitalIdentity::ENTITY_STUDENT => Student::query()->find($identity->entity_id)?->person,
            DigitalIdentity::ENTITY_TEACHER => Teacher::query()->find($identity->entity_id)?->person,
            default => null,
        };
    }

    private function entityType(Person $person): ?string
    {
        return $person->primaryStudent ? DigitalIdentity::ENTITY_STUDENT : ($person->primaryTeacher ? DigitalIdentity::ENTITY_TEACHER : 'employee');
    }

    private function entityId(Person $person): ?int
    {
        return $person->primaryStudent?->id ?? $person->primaryTeacher?->id ?? $person->primaryEmployee?->id;
    }

    /** @return array<string,mixed> */
    private function personDisplay(Person $person): array
    {
        return [
            'id' => $person->id,
            'display_name' => trim("{$person->last_name} {$person->first_name} {$person->middle_name}"),
            'category' => $this->entityType($person),
        ];
    }

    private function encodeToken(array $payload): string
    {
        $encoded = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        return self::TOKEN_PREFIX.$encoded.'.'.$this->sign($encoded);
    }

    private function sign(string $payload): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $payload, $this->signingKey(), true));
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function signingKey(): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            return $decoded !== false ? $decoded : $key;
        }
        return $key;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4)) ?: '';
    }

    private function requestId(?Request $request): string
    {
        return $request?->headers->get('X-Request-ID') ?: (string) Str::uuid();
    }
}
