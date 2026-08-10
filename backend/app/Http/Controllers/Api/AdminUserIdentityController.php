<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\ExternalIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Способы входа чужой учётной записи — для администратора.
 *
 * Нужно ровно на один случай, и случай не редкий: человек потерял доступ к мессенджеру
 * или сменил номер. Без отвязки со стороны внешний аккаунт остаётся занятым навсегда,
 * и привязать его заново нельзя ни ему, ни кому-либо ещё.
 *
 * Привязать за человека администратор не может и не должен: подпись провайдера
 * приходит тому, кто у мессенджера, а не тому, кто у административного экрана.
 */
class AdminUserIdentityController extends Controller
{
    public function __construct(private readonly ExternalIdentityService $identities)
    {
    }

    public function index(User $user): JsonResponse
    {
        return response()->json([
            'data' => $user->identities()->orderBy('provider')->get()->map(fn (UserIdentity $identity): array => [
                'id' => $identity->id,
                'provider' => $identity->provider,
                'display_name' => $identity->display_name,
                'linked_at' => $identity->linked_at?->toISOString(),
            ]),
        ]);
    }

    public function destroy(Request $request, User $user, UserIdentity $identity): JsonResponse
    {
        abort_unless($identity->user_id === $user->id, Response::HTTP_NOT_FOUND);

        $this->identities->unlink($identity, $request->user(), $request);

        return response()->json(['message' => 'Способ входа отвязан администратором.']);
    }
}
