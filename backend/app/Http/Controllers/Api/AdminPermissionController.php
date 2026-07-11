<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AdminPermissionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $permissions = Permission::query()
            ->with(['roles' => fn ($query) => $query->orderBy('name')])
            ->withCount('roles')
            ->when($request->string('module')->toString(), fn ($query, string $module) => $query->where('module', $module))
            ->when($request->filled('active'), fn ($query) => $query->where('active', $request->boolean('active')))
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('module', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderBy('module')
            ->orderBy('code')
            ->get();

        return PermissionResource::collection($permissions);
    }

    public function store(Request $request): PermissionResource
    {
        $permission = Permission::create($this->validated($request));
        AuditLogService::log('permissions', 'create', $permission, null, $permission->toArray(), $request);

        return new PermissionResource($permission->load('roles')->loadCount('roles'));
    }

    public function update(Request $request, Permission $permission): PermissionResource
    {
        $old = $permission->getAttributes();
        $permission->update($this->validated($request, $permission));
        AuditLogService::log('permissions', 'update', $permission, $old, $permission->fresh()->getAttributes(), $request);

        return new PermissionResource($permission->refresh()->load('roles')->loadCount('roles'));
    }

    public function destroy(Permission $permission): JsonResponse
    {
        if ($permission->system) {
            return response()->json(['message' => 'Системное разрешение нельзя удалить.'], 422);
        }

        $old = $permission->getAttributes();
        $permission->delete();
        AuditLogService::log('permissions', 'delete', ['type' => 'Permission', 'id' => $old['id'] ?? null], $old, null, request());

        return response()->json(null, 204);
    }

    public function assignRoles(Request $request, Permission $permission): PermissionResource
    {
        $data = $request->validate([
            'role_ids' => ['array'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')],
        ]);

        $old = $permission->roles()->pluck('roles.id')->all();
        $permission->roles()->sync($data['role_ids'] ?? []);
        AuditLogService::log('permissions', 'assign_roles', $permission, ['role_ids' => $old], ['role_ids' => $data['role_ids'] ?? []], $request);

        return new PermissionResource($permission->refresh()->load('roles')->loadCount('roles'));
    }

    public function roles(): AnonymousResourceCollection
    {
        return \App\Http\Resources\RoleResource::collection(Role::query()->with('permissions')->withCount('assignedUsers')->orderBy('name')->get());
    }

    private function validated(Request $request, ?Permission $permission = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:120', Rule::unique('permissions', 'code')->ignore($permission?->id)],
            'name' => ['required', 'string', 'max:255'],
            'module' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'system' => ['boolean'],
            'active' => ['boolean'],
        ]);
    }
}
