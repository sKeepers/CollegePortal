<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuditLogService
{
    public static function log(
        string $module,
        string $action,
        Model|array|null $entity = null,
        array|Model|null $old = null,
        array|Model|null $new = null,
        ?Request $request = null,
        ?User $user = null,
        ?int $personId = null,
        ?string $requestId = null,
    ): ?AuditLog {
        try {
            $request ??= request();
            $user ??= $request?->user();
            $entityType = null;
            $entityId = null;

            if ($entity instanceof Model) {
                $entityType = class_basename($entity);
                $entityId = $entity->getKey();
            } elseif (is_array($entity)) {
                $entityType = $entity['type'] ?? null;
                $entityId = $entity['id'] ?? null;
            }

            return AuditLog::create([
                'created_at' => now(),
                'user_id' => $user?->id,
                'person_id' => $personId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'module' => $module,
                'old_values' => self::sanitize($old),
                'new_values' => self::sanitize($new),
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'request_id' => $requestId ?: $request?->headers->get('X-Request-ID') ?: (string) Str::uuid(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Audit log write failed.', ['message' => $exception->getMessage()]);
            return null;
        }
    }

    private static function sanitize(array|Model|null $values): ?array
    {
        if ($values instanceof Model) {
            $values = $values->getAttributes();
        }

        if ($values === null) {
            return null;
        }

        return self::redactSensitiveValues(Arr::except($values, [
            'password',
            'remember_token',
            'api_token_hash',
            'token',
            'snils',
            'snils_hash',
            'series',
            'number',
            'number_hash',
            'storage_path',
            'stored_path',
            'fis_raw_data',
        ]));
    }

    private static function redactSensitiveValues(array $values): array
    {
        $sensitiveKeys = [
            'email',
            'phone',
            'address',
            'registration_address',
            'residential_address',
            'place_birth',
            'inn',
            'passport',
            'identity_document',
        ];

        foreach ($values as $key => $value) {
            if (in_array((string) $key, $sensitiveKeys, true)) {
                $values[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $values[$key] = self::redactSensitiveValues($value);
            }
        }

        return $values;
    }
}
