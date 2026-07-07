<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class AdminRoleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $roles = Role::query()
            ->withCount('assignedUsers')
            ->when($request->string('search')->toString(), function ($query, string $search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->get();

        return RoleResource::collection($roles);
    }

    public function store(Request $request): RoleResource
    {
        $role = Role::create($this->validated($request));

        return new RoleResource($role->loadCount('assignedUsers'));
    }

    public function update(Request $request, Role $role): RoleResource
    {
        $role->update($this->validated($request, $role));

        return new RoleResource($role->refresh()->loadCount('assignedUsers'));
    }

    public function destroy(Role $role): JsonResponse
    {
        if ($role->assignedUsers()->exists() || $role->users()->exists()) {
            return response()->json(['message' => 'Нельзя удалить роль, назначенную пользователям.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $role->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    private function validated(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:100', Rule::unique('roles', 'code')->ignore($role?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
