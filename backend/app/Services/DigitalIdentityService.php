<?php

namespace App\Services;

use App\Models\DigitalIdentity;
use App\Models\Employee;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DigitalIdentityService
{
    public function issue(string $entityType, int $entityId, ?string $expiresAt, Request $request, string $auditModule = 'digital_identity', string $auditAction = 'issue_qr'): DigitalIdentity
    {
        $this->ensureOwnerExists($entityType, $entityId);
        $personId = $this->ownerPersonId($entityType, $entityId);

        $identity = DB::transaction(function () use ($entityType, $entityId, $expiresAt, $personId): DigitalIdentity {
            DigitalIdentity::query()
                ->where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->whereIn('status', [DigitalIdentity::STATUS_ACTIVE, DigitalIdentity::STATUS_SUSPENDED])
                ->update([
                    'status' => DigitalIdentity::STATUS_REVOKED,
                    'revoked_at' => now(),
                    'updated_at' => now(),
                ]);

            return DigitalIdentity::create([
                'person_id' => $personId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'token' => (string) Str::uuid(),
                'status' => DigitalIdentity::STATUS_ACTIVE,
                'issued_at' => now(),
                'expires_at' => $expiresAt,
            ]);
        });

        AuditLogService::log($auditModule, $auditAction, $identity, null, $identity->toArray(), $request);

        return $identity;
    }

    private function ownerPersonId(string $entityType, int $entityId): ?int
    {
        return match ($entityType) {
            DigitalIdentity::ENTITY_STUDENT => Student::whereKey($entityId)->value('person_id'),
            DigitalIdentity::ENTITY_TEACHER => Teacher::whereKey($entityId)->value('person_id'),
            DigitalIdentity::ENTITY_EMPLOYEE => Employee::whereKey($entityId)->value('person_id'),
            default => null,
        };
    }

    private function ensureOwnerExists(string $entityType, int $entityId): void
    {
        $exists = match ($entityType) {
            DigitalIdentity::ENTITY_STUDENT => Student::whereKey($entityId)->exists(),
            DigitalIdentity::ENTITY_TEACHER => Teacher::whereKey($entityId)->exists(),
            DigitalIdentity::ENTITY_EMPLOYEE => Employee::whereKey($entityId)->exists(),
            default => false,
        };

        if (! $exists) {
            throw ValidationException::withMessages([
                'entity_id' => ['Владелец цифрового пропуска не найден.'],
            ]);
        }
    }
}
