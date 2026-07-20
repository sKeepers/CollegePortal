<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\QrSvgService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class AdminUserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $users = User::query()
            ->with(['role.permissions', 'roles.permissions'])
            ->when($request->string('search')->toString(), function ($query, string $search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->string('status')->toString(), function ($query, string $status) {
                if ($status === 'active') {
                    $query->where('is_active', true);
                }
                if ($status === 'blocked') {
                    $query->where('is_active', false);
                }
            })
            ->orderBy('name')
            ->paginate((int) $request->integer('per_page', 50));

        return UserResource::collection($users);
    }

    public function store(Request $request): UserResource
    {
        $data = $this->validated($request);

        $user = User::create([
            ...$data,
            'password' => Hash::make($data['password']),
            'is_active' => $data['is_active'] ?? true,
        ]);
        $this->syncPrimaryRole($user);
        AuditLogService::log('users', 'create', $user, null, $user->fresh()->toArray(), $request);

        return new UserResource($user->load(['role.permissions', 'roles.permissions']));
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user->load(['role.permissions', 'roles.permissions']));
    }

    public function update(Request $request, User $user): UserResource
    {
        $old = $user->getAttributes();
        $data = $this->validated($request, $user);

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);
        $this->syncPrimaryRole($user);
        AuditLogService::log('users', 'update', $user, $old, $user->fresh()->getAttributes(), $request);

        return new UserResource($user->refresh()->load(['role.permissions', 'roles.permissions']));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()?->id === $user->id) {
            return response()->json(['message' => 'Нельзя удалить текущего пользователя.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $old = $user->getAttributes();
        $user->delete();
        AuditLogService::log('users', 'delete', ['type' => 'User', 'id' => $old['id'] ?? null], $old, null, $request);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function block(Request $request, User $user): UserResource|JsonResponse
    {
        if ($request->user()?->id === $user->id) {
            return response()->json(['message' => 'Нельзя заблокировать текущего пользователя.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $old = $user->getAttributes();
        $user->forceFill(['is_active' => false, 'api_token_hash' => null])->save();
        AuditLogService::log('users', 'block', $user, $old, $user->fresh()->getAttributes(), $request);

        return new UserResource($user->refresh()->load(['role.permissions', 'roles.permissions']));
    }

    public function unblock(User $user): UserResource
    {
        $old = $user->getAttributes();
        $user->forceFill(['is_active' => true])->save();
        AuditLogService::log('users', 'unblock', $user, $old, $user->fresh()->getAttributes(), request());

        return new UserResource($user->refresh()->load(['role.permissions', 'roles.permissions']));
    }


    public function createTemporaryPassword(Request $request, User $user, QrSvgService $qrSvgService): JsonResponse
    {
        if ($denied = $this->denyUnlessCredentialAdmin($request)) {
            return $denied;
        }

        if ($request->user()?->id === $user->id) {
            return response()->json(['message' => 'Нельзя создать временный пароль для текущей сессии.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $temporaryPassword = $this->generateTemporaryPassword();
        $old = [
            'must_change_password' => (bool) $user->must_change_password,
            'api_token_hash_present' => filled($user->api_token_hash),
        ];

        $user->forceFill([
            'password' => Hash::make($temporaryPassword),
            'api_token_hash' => null,
            'must_change_password' => true,
        ])->save();

        AuditLogService::log('users', 'temporary_password_created', $user, $old, [
            'must_change_password' => true,
            'sessions_revoked' => true,
        ], $request);

        return response()->json([
            'data' => [
                'user' => new UserResource($user->refresh()->load(['role.permissions', 'roles.permissions'])),
                'temporary_password' => $temporaryPassword,
                'card' => $this->credentialCardPayload($request, $user, $qrSvgService, $temporaryPassword),
            ],
        ]);
    }

    public function credentialCard(Request $request, User $user, QrSvgService $qrSvgService): JsonResponse
    {
        if ($denied = $this->denyUnlessCredentialAdmin($request)) {
            return $denied;
        }

        AuditLogService::log('users', 'credential_card_printed', $user, null, [
            'temporary_password_included' => false,
        ], $request);

        return response()->json([
            'data' => $this->credentialCardPayload($request, $user, $qrSvgService),
        ]);
    }
    public function assignRoles(Request $request, User $user): UserResource
    {
        $data = $request->validate([
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'primary_role_id' => ['nullable', 'integer', 'exists:roles,id'],
        ]);

        $roleIds = collect($data['role_ids'])->map(fn ($id) => (int) $id)->unique()->values();
        $primaryRoleId = (int) ($data['primary_role_id'] ?? $roleIds->first());

        if (! $roleIds->contains($primaryRoleId)) {
            $roleIds->prepend($primaryRoleId);
        }

        $sync = $roleIds->mapWithKeys(fn ($roleId) => [$roleId => ['is_primary' => $roleId === $primaryRoleId]])->all();
        $old = ['role_id' => $user->role_id, 'role_ids' => $user->roles()->pluck('roles.id')->values()->all()];
        $user->roles()->sync($sync);
        $user->forceFill(['role_id' => $primaryRoleId])->save();
        AuditLogService::log('users', 'assign_roles', $user, $old, ['role_id' => $primaryRoleId, 'role_ids' => $roleIds->all()], $request);

        return new UserResource($user->refresh()->load(['role.permissions', 'roles.permissions']));
    }

    public function roles(): JsonResponse
    {
        return response()->json([
            'data' => Role::query()->orderBy('name')->get(['id', 'name', 'code', 'description']),
        ]);
    }

    public function people(Request $request): JsonResponse
    {
        $type = $request->string('type')->toString();
        $search = $request->string('search')->toString();

        $items = match ($type) {
            'student' => Student::query()
                ->with('group:id,name')
                ->when($search, fn ($query) => $query->whereRaw("concat(last_name, ' ', first_name, ' ', coalesce(middle_name, '')) ilike ?", ["%{$search}%"]))
                ->orderBy('last_name')
                ->limit(30)
                ->get()
                ->map(fn (Student $student) => [
                    'id' => $student->id,
                    'type' => 'student',
                    'name' => trim("{$student->last_name} {$student->first_name} {$student->middle_name}"),
                    'description' => $student->group?->name,
                ]),
            'teacher' => Teacher::query()
                ->when($search, fn ($query) => $query->whereRaw("concat(last_name, ' ', first_name, ' ', coalesce(middle_name, '')) ilike ?", ["%{$search}%"]))
                ->orderBy('last_name')
                ->limit(30)
                ->get()
                ->map(fn (Teacher $teacher) => [
                    'id' => $teacher->id,
                    'type' => 'teacher',
                    'name' => trim("{$teacher->last_name} {$teacher->first_name} {$teacher->middle_name}"),
                    'description' => $teacher->department,
                ]),
            default => collect(),
        };

        return response()->json(['data' => $items->values()]);
    }


    private function denyUnlessCredentialAdmin(Request $request): ?JsonResponse
    {
        if (! $request->user()?->hasRole('admin')) {
            return response()->json(['message' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function credentialCardPayload(Request $request, User $user, QrSvgService $qrSvgService, ?string $temporaryPassword = null): array
    {
        $portalUrl = rtrim($request->getSchemeAndHttpHost(), '/');
        $loginUrl = $portalUrl.'/login';
        $roles = $user->relationLoaded('roles') ? $user->roles : $user->roles()->get();
        $roleLabel = $roles->pluck('name')->filter()->join(', ') ?: $user->role?->name;

        return array_filter([
            'organization' => config('app.name', 'CollegePortal'),
            'full_name' => $user->name,
            'role' => $roleLabel ?: 'Роль не указана',
            'login' => $user->email,
            'temporary_password' => $temporaryPassword,
            'portal_url' => $portalUrl,
            'login_url' => $loginUrl,
            'login_qr_svg' => $qrSvgService->renderSvg($loginUrl),
            'issued_at' => now()->toISOString(),
            'issued_by' => $request->user()?->name,
            'instruction' => 'При первом входе смените временный пароль.',
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function generateTemporaryPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $password = '';

        for ($i = 0; $i < 14; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }
    private function syncPrimaryRole(User $user): void
    {
        if (! $user->role_id) {
            return;
        }

        $currentRoleIds = $user->roles()->pluck('roles.id')->map(fn ($id) => (int) $id)->all();
        $roleIds = collect([$user->role_id, ...$currentRoleIds])->unique()->values();
        $sync = $roleIds->mapWithKeys(fn ($roleId) => [$roleId => ['is_primary' => (int) $roleId === (int) $user->role_id]])->all();
        $user->roles()->sync($sync);
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $validator = Validator::make($request->all(), [
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'person_type' => ['nullable', Rule::in(['student', 'teacher', 'employee', 'applicant', 'guest', 'alumni'])],
            'person_id' => ['nullable', 'integer', 'min:1', 'exists:people,id'],
        ], [
            'name.required' => 'Введите имя пользователя.',
            'email.required' => 'Введите email.',
            'email.email' => 'Введите корректный email.',
            'email.unique' => 'Пользователь с таким email уже существует.',
            'password.required' => 'Введите пароль.',
            'password.min' => 'Пароль должен содержать не менее :min символов.',
            'role_id.required' => 'Выберите роль.',
            'role_id.exists' => 'Выбранная роль не найдена.',
            'person_id.exists' => 'Выбранная запись Person не найдена.',
        ]);

        if ($validator->fails()) {
            throw new HttpResponseException(response()->json([
                'message' => 'Проверьте заполнение формы.',
                'errors' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $validator->validated();
    }
}
