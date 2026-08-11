<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Notifications\NotificationSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
