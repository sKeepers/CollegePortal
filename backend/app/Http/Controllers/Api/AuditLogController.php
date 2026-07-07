<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $logs = AuditLog::query()
            ->with('user.role')
            ->when($request->integer('user_id'), fn ($query, int $userId) => $query->where('user_id', $userId))
            ->when($request->string('module')->toString(), fn ($query, string $module) => $query->where('module', $module))
            ->when($request->string('action')->toString(), fn ($query, string $action) => $query->where('action', $action))
            ->when($request->date('date_from'), fn ($query, $date) => $query->where('created_at', '>=', $date->startOfDay()))
            ->when($request->date('date_to'), fn ($query, $date) => $query->where('created_at', '<=', $date->endOfDay()))
            ->when($request->string('search')->toString(), function ($query, string $search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('module', 'like', "%{$search}%")
                        ->orWhere('action', 'like', "%{$search}%")
                        ->orWhere('entity_type', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($userQuery) => $userQuery->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
                });
            })
            ->latest('created_at')
            ->paginate((int) $request->integer('per_page', 50));

        return AuditLogResource::collection($logs);
    }

    public function show(AuditLog $auditLog): AuditLogResource
    {
        return new AuditLogResource($auditLog->load('user.role'));
    }
}
