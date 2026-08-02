<?php

namespace App\Services;

use App\Models\DigitalIdentity;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;

class DigitalPassRevocationService
{
    public function revokeForStudent(Student $student): void
    {
        $this->revoke($student->person_id, [DigitalIdentity::ENTITY_STUDENT => $student->id]);
    }

    public function revokeForTeacher(Teacher $teacher): void
    {
        $this->revoke($teacher->person_id, [DigitalIdentity::ENTITY_TEACHER => $teacher->id]);
    }

    public function revokeForUser(User $user): void
    {
        $this->revoke($user->person_id, [
            DigitalIdentity::ENTITY_STUDENT => $user->student()->value('id'),
            DigitalIdentity::ENTITY_TEACHER => $user->teacher()->value('id'),
        ]);
    }

    /** @param array<string, int|null> $owners */
    private function revoke(?int $personId, array $owners): void
    {
        $owners = array_filter($owners);

        if ($personId === null && $owners === []) {
            return;
        }

        DigitalIdentity::query()
            ->whereIn('status', [DigitalIdentity::STATUS_ACTIVE, DigitalIdentity::STATUS_SUSPENDED])
            ->where(function ($query) use ($personId, $owners): void {
                if ($personId !== null) {
                    $query->orWhere('person_id', $personId);
                }

                foreach ($owners as $entityType => $entityId) {
                    $query->orWhere(function ($ownerQuery) use ($entityType, $entityId): void {
                        $ownerQuery
                            ->where('entity_type', $entityType)
                            ->where('entity_id', $entityId);
                    });
                }
            })
            ->each(function (DigitalIdentity $identity): void {
                $old = $identity->getAttributes();
                $identity->update([
                    'status' => DigitalIdentity::STATUS_REVOKED,
                    'revoked_at' => now(),
                ]);

                AuditLogService::log('digital_identity', 'revoke_owner_deactivated', $identity, $old, $identity->fresh()->getAttributes());
            });
    }
}
