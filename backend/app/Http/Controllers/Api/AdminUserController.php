<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\Employee;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Rules\SelfChosenPassword;
use App\Services\AuditLogService;
use App\Support\Auth\TemporaryPassword;
use App\Services\AccountProvisioningService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use LogicException;
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
            // Пароль задал не сам человек, а администратор — значит, после входа
            // портал предложит завести свой.
            'must_change_password' => true,
        ]);
        $this->syncPrimaryRole($user);
        AuditLogService::log('users', 'create', $user, null, $user->fresh()->toArray(), $request);

        return new UserResource($user->load(['role.permissions', 'roles.permissions']));
    }

    public function provision(Request $request, AccountProvisioningService $accounts): JsonResponse
    {
        $data = $request->validate([
            'profile_type' => ['required', Rule::in(['student', 'teacher', 'employee'])],
            'profile_id' => ['required', 'integer', 'min:1'],
        ]);

        $profile = match ($data['profile_type']) {
            'student' => Student::findOrFail($data['profile_id']),
            'teacher' => Teacher::findOrFail($data['profile_id']),
            'employee' => Employee::findOrFail($data['profile_id']),
        };
        try {
            $account = $accounts->provision($profile);
        } catch (LogicException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        AuditLogService::log('users', 'provision', $account->user, null, [
            'profile_type' => $data['profile_type'],
            'profile_id' => $data['profile_id'],
            'role' => $account->role,
        ], $request);

        return response()->json(['data' => [
            'login' => $account->login,
            'password' => $account->password,
            'name' => $account->name,
            // Код остаётся: по нему сверяются права и пишется журнал. Рядом —
            // название, потому что карточку доступа печатают и отдают человеку,
            // а «Роль: student» на бумаге человеку ничего не говорит.
            'role' => $account->role,
            'role_name' => $account->roleName,
        ]], Response::HTTP_CREATED);
    }

    /**
     * Сброс пароля прямо из карточки человека. Пароль возвращается один раз и
     * нигде не сохраняется: в базе хеш, в аудите — только факт сброса. Старый
     * пароль восстановить нельзя и после сброса он перестает работать, поэтому
     * экран обязан предупредить об этом до нажатия, а не после.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'profile_type' => ['required', Rule::in(['student', 'teacher', 'employee'])],
            'profile_id' => ['required', 'integer', 'min:1'],
        ]);

        $profile = match ($data['profile_type']) {
            'student' => Student::findOrFail($data['profile_id']),
            'teacher' => Teacher::findOrFail($data['profile_id']),
            'employee' => Employee::findOrFail($data['profile_id']),
        };

        $accounts = $this->profileAccounts($profile);
        $active = $accounts->filter(fn (User $candidate): bool => (bool) $candidate->is_active)->values();

        if ($accounts->isEmpty()) {
            return response()->json([
                'message' => 'У этого человека нет учетной записи. Сначала создайте ее.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Отключённая запись — не «нет записи». Сказать «создайте» тому, у кого
        // она есть, значит отправить его заводить вторую, а две записи у одного
        // человека и есть причина этой правки.
        if ($active->isEmpty()) {
            return response()->json([
                'message' => 'Учетная запись этого человека отключена. Включите ее в разделе «Пользователи», потом сбрасывайте пароль.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Портал не угадывает, какой из двух записей сбрасывать пароль.
        // До 01.09.2026 угадывал: `first()` без порядка и без отбора
        // действующих — и сброс дважды попал в отключённую, а человек остался
        // без входа, думая, что пароль ему выдали.
        if ($active->count() > 1) {
            return response()->json([
                'message' => 'У этого человека несколько действующих учетных записей ('.$active->count().'). Портал не выбирает за вас: откройте раздел «Пользователи» и сбросьте пароль нужной.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $active->first();

        $password = TemporaryPassword::generate();
        // Выданный пароль временный: после входа портал предложит завести свой,
        // а через месяц он перестанет работать сам. Пять цифр, которые здесь
        // были до 23.08.2026, у неиспользованной записи жили годами.
        $user->forceFill([
            'password' => Hash::make($password),
            'must_change_password' => true,
            'password_expires_at' => TemporaryPassword::expiresAt(),
        ])->save();

        AuditLogService::log('users', 'reset_password', $user, null, [
            'profile_type' => $data['profile_type'],
            'profile_id' => $data['profile_id'],
        ], $request);

        return response()->json(['data' => [
            'login' => $this->loginOf($user),
            'password' => $password,
            'name' => $user->name,
        ]]);
    }

    /**
     * Задать пароль вот этой учётной записи.
     *
     * Сброс по карточке человека выбирать между двумя действующими записями
     * отказывается — и правильно делает: пока он выбирал сам, пароль дважды
     * ушёл в отключённую запись. Но отказ отправлял в раздел «Пользователи», а
     * там пароль можно было только **придумать руками** в правке карточки:
     * ни срока, ни отметки «выдан», ни карточки доступа с логином. Здесь та же
     * выдача, что по карточке человека, только запись называет человек.
     *
     * Отключённой записи пароль не выдаётся: войти по нему нельзя, а выдавший
     * будет уверен, что доступ передан, — это ровно та беда, из-за которой
     * писалась правка сброса.
     */
    public function resetPasswordForUser(Request $request, User $user): JsonResponse
    {
        // Свой пароль этой ручкой не сбрасывается. Выданный показывается один
        // раз, а смена пароля спрашивает текущий: не записав показанное,
        // администратор закрывает вход себе самому — и починить это будет
        // некому. Удаление и блокировка себя закрыты здесь же и по той же
        // причине.
        if ($request->user()?->id === $user->id) {
            return response()->json([
                'message' => 'Свой пароль меняйте в разделе «Моя учётная запись»: выданный показывается один раз, и, не записав его, вы закроете вход себе.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Учетная запись отключена — по выданному паролю в нее не войти. Сначала включите запись, потом сбрасывайте пароль.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $password = TemporaryPassword::generate();
        // Тот же временный пароль, что выдаёт портал по карточке человека: со
        // сроком и с требованием сменить. Пароль, набранный руками в правке
        // карточки, срока не получает вовсе — и остаётся у неиспользованной
        // записи навсегда.
        $user->forceFill([
            'password' => Hash::make($password),
            'must_change_password' => true,
            'password_expires_at' => TemporaryPassword::expiresAt(),
        ])->save();

        AuditLogService::log('users', 'reset_password', $user, null, [
            // Признак того, что запись выбрал человек, а не отбор по карточке:
            // в журнале эти два случая иначе неразличимы.
            'chosen_by_hand' => true,
        ], $request);

        return response()->json(['data' => [
            'login' => $this->loginOf($user),
            'password' => $password,
            'name' => $user->name,
            'role' => $user->role?->code,
            'role_name' => $user->role?->name,
        ]]);
    }

    /**
     * Чем человек войдёт.
     *
     * Портал пускает и по `username`, и по `email` (`AuthController::login`), а
     * `username` заполняется не всегда: учётная запись, заведённая вручную,
     * получает только почту. Печатать в карточке доступа пустую строку — значит
     * выдать пароль без входа, поэтому берём то, чем войти можно.
     */
    private function loginOf(User $user): ?string
    {
        return $user->username ?: $user->email;
    }

    /**
     * Учётные записи, которые могут принадлежать этой карточке.
     *
     * Возвращается **список**, а не одна запись, и это существо правки. Раньше
     * здесь стоял `first()` без порядка и без отбора действующих: при двух
     * записях у одного человека база отдавала любую, и сброс пароля дважды
     * попал в отключённую — человек остался без входа, а тот, кто сбрасывал,
     * был уверен, что выдал пароль.
     *
     * Ссылка из самой карточки (`user_id`) — не угадывание, а прямое указание,
     * и она по-прежнему главнее: если карточка называет запись, берётся она.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function profileAccounts(Student|Teacher|Employee $profile): Collection
    {
        if (($profile->user_id ?? null) !== null) {
            $named = User::find($profile->user_id);

            return $named ? collect([$named]) : collect();
        }

        if (! $profile->person_id) {
            return collect();
        }

        return User::query()
            ->where('person_id', $profile->person_id)
            ->orderBy('id')
            ->get();
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
            // Пароль задан со стороны, а не самим человеком. Правка карточки без
            // пароля отметку не трогает — иначе она возвращалась бы тому, кто свой
            // пароль давно завёл, при любом изменении роли или почты.
            $data['must_change_password'] = true;
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
            // Тот же набор требований, что и в «Моей учётной записи»: пароль, который
            // задаёт человек, не должен зависеть от того, кто именно его набирает.
            'password' => [$user ? 'nullable' : 'required', 'string', 'max:255', new SelfChosenPassword],
            'is_active' => ['sometimes', 'boolean'],
            'person_type' => ['nullable', Rule::in(['student', 'teacher', 'employee', 'applicant', 'guest', 'alumni'])],
            'person_id' => ['nullable', 'integer', 'min:1', 'exists:people,id'],
        ], [
            'name.required' => 'Введите имя пользователя.',
            'email.required' => 'Введите email.',
            'email.email' => 'Введите корректный email.',
            'email.unique' => 'Пользователь с таким email уже существует.',
            'password.required' => 'Введите пароль.',
            'role_id.required' => 'Выберите роль.',
            'role_id.exists' => 'Выбранная роль не найдена.',
            'person_id.exists' => 'Выбранная личная карточка не найдена.',
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
