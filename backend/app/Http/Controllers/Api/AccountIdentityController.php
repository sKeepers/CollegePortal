<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserIdentity;
use App\Services\ExternalIdentityService;
use App\Support\Auth\Providers\ExternalIdentityProviders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

/**
 * «Способы входа» в разделе «Моя учётная запись».
 *
 * Прав нет намеренно: своими способами входа человек распоряжается сам. Но и привязка,
 * и отвязка требуют текущего пароля — иначе перехваченная сессия позволила бы привязать
 * чужой мессенджер и получить постоянный вход.
 */
class AccountIdentityController extends Controller
{
    public function __construct(
        private readonly ExternalIdentityService $identities,
        private readonly ExternalIdentityProviders $providers,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $identities = UserIdentity::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('provider')
            ->get()
            ->map(fn (UserIdentity $identity): array => [
                'id' => $identity->id,
                'provider' => $identity->provider,
                'provider_name' => $this->providers->get($identity->provider)?->name() ?? $identity->provider,
                'display_name' => $identity->display_name,
                'linked_at' => $identity->linked_at?->toISOString(),
            ]);

        return response()->json([
            'data' => $identities,
            // Пустой список означает, что привязывать пока нечего: провайдеры придут
            // с AUTH-003 и AUTH-004. Интерфейсу об этом надо знать, чтобы не показывать
            // кнопку, которая никуда не ведёт.
            'available' => $this->providers->available(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:32'],
            'current_password' => ['required', 'string'],
            'payload' => ['required', 'array'],
        ]);

        if (! Hash::check($data['current_password'], $request->user()->password)) {
            return $this->wrongPassword();
        }

        $identity = $this->identities->link($request->user(), $data['provider'], $data['payload'], $request);

        return response()->json(['data' => ['id' => $identity->id, 'provider' => $identity->provider]], Response::HTTP_CREATED);
    }

    public function destroy(Request $request, UserIdentity $identity): JsonResponse
    {
        // Свои способы входа, и только свои: чужие снимает администратор отдельным маршрутом.
        abort_unless($identity->user_id === $request->user()->id, Response::HTTP_NOT_FOUND);

        $data = $request->validate(['current_password' => ['required', 'string']]);

        if (! Hash::check($data['current_password'], $request->user()->password)) {
            return $this->wrongPassword();
        }

        $this->identities->unlink($identity, $request->user(), $request);

        return response()->json(['message' => 'Способ входа отвязан.']);
    }

    private function wrongPassword(): JsonResponse
    {
        return response()->json([
            'message' => 'Текущий пароль указан неверно.',
            'errors' => ['current_password' => ['Текущий пароль указан неверно.']],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
