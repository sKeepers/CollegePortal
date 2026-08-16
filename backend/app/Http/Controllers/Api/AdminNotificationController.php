<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationSubscription;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Снятие чужой подписки на уведомления — исполнитель распоряжения директора.
 *
 * Зачем это вообще есть. Владелец решил: родитель получает уведомления о студенте без
 * его согласия, и **сам студент отключить их не может**; изменить порядок может только
 * распоряжение директора. Значит распоряжение должен кто-то исполнять в портале — иначе
 * первое же из них исполнят правкой базы руками, без следа и без возможности проверить.
 *
 * **След обязателен.** Снятие пишется в журнал аудита: кто снял, у кого, что именно и
 * когда. Право снимать чужое без следа превратило бы этот раздел в способ тихо отрезать
 * человека от того, что ему полагается знать.
 *
 * Заводить подписку отсюда нельзя — только снимать. Подписка это согласие, а согласие
 * за человека не оформляют; исключение с родителем принято владельцем отдельно и
 * заводится не этим маршрутом.
 */
class AdminNotificationController extends Controller
{
    public function index(User $user): JsonResponse
    {
        $subscriptions = NotificationSubscription::query()
            ->where('user_id', $user->id)
            ->with('subject')
            ->orderBy('event')
            ->get()
            ->map(fn (NotificationSubscription $row): array => [
                'id' => $row->id,
                'event' => $row->event,
                'channel' => $row->channel,
                'subject' => $row->subject?->name,
                'own' => $row->subject_user_id === $row->user_id,
            ]);

        return response()->json(['data' => $subscriptions]);
    }

    public function destroy(Request $request, User $user, NotificationSubscription $subscription): JsonResponse
    {
        // Подписка чужая — не про этого человека: молчаливое согласие тут опаснее отказа.
        abort_unless($subscription->user_id === $user->id, 404);

        AuditLogService::log('auth', 'notification_unsubscribed_by_administrator', $subscription, [
            'event' => $subscription->event,
            'channel' => $subscription->channel,
            'user_id' => $subscription->user_id,
            'subject_user_id' => $subscription->subject_user_id,
        ], null, $request, $request->user());

        $subscription->delete();

        return response()->json(['message' => 'Подписка снята.']);
    }
}
