<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Notifications\MaxLinkService;
use App\Services\Notifications\NotificationSubscriptionService;
use App\Support\Notifications\MaxNotificationChannel;
use App\Support\Notifications\NotificationChannels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Галочки уведомлений в «Моей учётной записи».
 *
 * Прав нет намеренно — как и у остального раздела: своими уведомлениями человек
 * распоряжается сам. Текущий пароль здесь тоже не спрашивается: галочка не даёт доступа
 * ни к чему, она только включает отправку тому, кто уже вошёл.
 */
class AccountNotificationController extends Controller
{
    public function __construct(private readonly NotificationSubscriptionService $subscriptions)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->subscriptions->overview($request->user())]);
    }

    /**
     * Одноразовый код для привязки мессенджера.
     *
     * Код выдаётся только вошедшему — этим и обеспечивается, что человек привязывает
     * себя, а не кого-то ещё. Пароль здесь не спрашивается: код ничего не открывает,
     * он лишь говорит боту, кто с ним разговаривает.
     */
    public function linkCode(Request $request, MaxLinkService $links): JsonResponse
    {
        if (app(NotificationChannels::class)->get(MaxNotificationChannel::CODE) === null) {
            return response()->json(['message' => 'Уведомления в MAX на этом портале не подключены.'], Response::HTTP_CONFLICT);
        }

        $code = $links->issueCode($request->user());

        return response()->json(['data' => [
            'code' => $code->code,
            'expires_at' => $code->expires_at->toISOString(),
            'bot_username' => config('services.max.bot_username'),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event' => ['required', 'string', 'max:64'],
            'channel' => ['required', 'string', 'max:32'],
            'enabled' => ['required', 'boolean'],
        ]);

        $this->subscriptions->set($request->user(), $data['event'], $data['channel'], $data['enabled'], $request);

        return response()->json(['data' => $this->subscriptions->overview($request->user())]);
    }
}
