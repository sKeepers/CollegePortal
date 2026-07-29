<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IssueDigitalIdentityRequest;
use App\Http\Resources\DigitalIdentityResource;
use App\Models\DigitalIdentity;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\AuditLogService;
use App\Services\QrSvgService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DigitalIdentityController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $canManage = Gate::allows('permission', 'digitalpasses.manage');
        $mineOnly = $request->boolean('mine') || ! $canManage;
        $identities = DigitalIdentity::query()
            ->when($mineOnly, fn ($query) => $this->scopeToCurrentUser($query, $request))
            ->when($request->string('entity_type')->toString(), fn ($query, string $type) => $query->where('entity_type', $type))
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->orderByDesc('issued_at')
            ->paginate(50);

        return DigitalIdentityResource::collection($identities);
    }

    public function issue(IssueDigitalIdentityRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $this->ensureOwnerExists($validated['entity_type'], (int) $validated['entity_id']);
        $personId = $this->ownerPersonId($validated['entity_type'], (int) $validated['entity_id']);

        $identity = DB::transaction(function () use ($validated, $personId): DigitalIdentity {
            DigitalIdentity::query()
                ->where('entity_type', $validated['entity_type'])
                ->where('entity_id', $validated['entity_id'])
                ->whereIn('status', [DigitalIdentity::STATUS_ACTIVE, DigitalIdentity::STATUS_SUSPENDED])
                ->update([
                    'status' => DigitalIdentity::STATUS_REVOKED,
                    'revoked_at' => now(),
                    'updated_at' => now(),
                ]);

            return DigitalIdentity::create([
                'person_id' => $personId,
                'entity_type' => $validated['entity_type'],
                'entity_id' => $validated['entity_id'],
                'token' => (string) Str::uuid(),
                'status' => DigitalIdentity::STATUS_ACTIVE,
                'issued_at' => now(),
                'expires_at' => $validated['expires_at'] ?? null,
            ]);
        });

        AuditLogService::log('digital_identity', 'issue_qr', $identity, null, $identity->toArray(), $request);

        return (new DigitalIdentityResource($identity))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function revoke(DigitalIdentity $digitalIdentity): DigitalIdentityResource
    {
        if ($digitalIdentity->status !== DigitalIdentity::STATUS_REVOKED) {
            $old = $digitalIdentity->getAttributes();
            $digitalIdentity->update([
                'status' => DigitalIdentity::STATUS_REVOKED,
                'revoked_at' => now(),
            ]);
            AuditLogService::log('digital_identity', 'revoke_qr', $digitalIdentity, $old, $digitalIdentity->fresh()->getAttributes(), request());
        }

        return new DigitalIdentityResource($digitalIdentity->fresh());
    }

    public function qr(Request $request, DigitalIdentity $digitalIdentity, QrSvgService $qrSvgService): Response
    {
        if (! Gate::allows('permission', 'digitalpasses.manage') && ! $this->belongsToCurrentUser($digitalIdentity, $request)) {
            abort(Response::HTTP_FORBIDDEN, 'У вас нет доступа к этому пропуску.');
        }

        $format = strtolower($request->query('format', 'svg'));

        if ($format === 'png') {
            return response($qrSvgService->renderPng($digitalIdentity->token), Response::HTTP_OK, [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'no-store, private',
                'X-QR-Content' => 'token',
            ]);
        }

        return response($qrSvgService->renderSvg($digitalIdentity->token), Response::HTTP_OK, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-QR-Content' => 'token',
        ]);
    }

    private function ownerPersonId(string $entityType, int $entityId): ?int
    {
        return match ($entityType) {
            DigitalIdentity::ENTITY_STUDENT => Student::whereKey($entityId)->value('person_id'),
            DigitalIdentity::ENTITY_TEACHER => Teacher::whereKey($entityId)->value('person_id'),
            default => null,
        };
    }

    private function ensureOwnerExists(string $entityType, int $entityId): void
    {
        $exists = match ($entityType) {
            DigitalIdentity::ENTITY_STUDENT => Student::whereKey($entityId)->exists(),
            DigitalIdentity::ENTITY_TEACHER => Teacher::whereKey($entityId)->exists(),
            default => false,
        };

        if (!$exists) {
            throw ValidationException::withMessages([
                'entity_id' => ['Владелец цифрового пропуска не найден.'],
            ]);
        }
    }

    private function scopeToCurrentUser($query, Request $request)
    {
        $studentId = $request->user()->student()->value('id');
        $teacherId = $request->user()->teacher()->value('id');
        $personId = $request->user()->person_id;

        return $query->where(function ($ownerQuery) use ($studentId, $teacherId, $personId): void {
            if ($studentId !== null) {
                $ownerQuery->orWhere(function ($studentQuery) use ($studentId): void {
                    $studentQuery
                        ->where('entity_type', DigitalIdentity::ENTITY_STUDENT)
                        ->where('entity_id', $studentId);
                });
            }

            if ($teacherId !== null) {
                $ownerQuery->orWhere(function ($teacherQuery) use ($teacherId): void {
                    $teacherQuery
                        ->where('entity_type', DigitalIdentity::ENTITY_TEACHER)
                        ->where('entity_id', $teacherId);
                });
            }

            if ($personId !== null) {
                $ownerQuery->orWhere('person_id', $personId);
            }

            if ($studentId === null && $teacherId === null && $personId === null) {
                $ownerQuery->whereRaw('1 = 0');
            }
        });
    }

    private function belongsToCurrentUser(DigitalIdentity $digitalIdentity, Request $request): bool
    {
        $studentId = $request->user()->student()->value('id');
        $teacherId = $request->user()->teacher()->value('id');
        $personId = $request->user()->person_id;

        return ($digitalIdentity->entity_type === DigitalIdentity::ENTITY_STUDENT && $studentId !== null && (int) $digitalIdentity->entity_id === (int) $studentId)
            || ($digitalIdentity->entity_type === DigitalIdentity::ENTITY_TEACHER && $teacherId !== null && (int) $digitalIdentity->entity_id === (int) $teacherId)
            || ($personId !== null && (int) $digitalIdentity->person_id === (int) $personId);
    }
}
