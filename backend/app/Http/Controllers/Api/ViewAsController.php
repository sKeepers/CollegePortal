<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Вход в режим «портал глазами человека» и выход из него.
 *
 * Оба маршрута объявлены **вне** `view.as` намеренно: они изменяющие, и внутри
 * режима их отверг бы его же запрет на запись — то есть выйти из режима стало бы
 * нельзя. Здесь они выполняются от имени самого администратора.
 *
 * Разбор целиком — `docs/VIEW_AS_PERSON.md`.
 */
class ViewAsController extends Controller
{
    public function start(Request $request, User $user): JsonResponse
    {
        $admin = $request->user();

        if ($user->id === $admin->id) {
            return $this->refuse('Смотреть своими же глазами незачем.');
        }

        if (! $user->is_active) {
            return $this->refuse('Учётная запись выключена — смотреть её глазами нельзя.');
        }

        // Решение владельца от 31.08.2026: глазами другого администратора не
        // смотрим. Данных это не открывает — администратор и так видит всё, — а
        // цепочки и путаницу создаёт.
        if ($user->hasRole('admin')) {
            return $this->refuse('Глазами другого администратора смотреть нельзя.');
        }

        if ($missing = $this->rightsTheWatcherLacks($admin, $user)) {
            return $this->refuse('У этого человека есть права, которых нет у вас: '.implode(', ', $missing).'.');
        }

        $admin->forceFill(['viewing_as_user_id' => $user->id])->save();

        // Колонка проставляется здесь явно, а не подхватывается из подмены: вход
        // в режим выполняется **до** неё и от имени администратора. Без этой
        // строки на вопрос «кто и чьими глазами» пришлось бы отвечать разбором
        // старых значений, а не парой колонок.
        AuditLogService::log('impersonation', 'start', $user, null, null, $request, $admin)
            ?->forceFill(['viewed_as_user_id' => $user->id])->save();

        return response()->json(['message' => 'Портал открыт глазами выбранного человека. Это только просмотр.']);
    }

    public function stop(Request $request): JsonResponse
    {
        $admin = $request->user();
        $wasViewing = $admin->viewing_as_user_id;

        if ($wasViewing === null) {
            return response()->json(['message' => 'Режим не был включён.']);
        }

        $admin->forceFill(['viewing_as_user_id' => null])->save();

        AuditLogService::log('impersonation', 'stop', null, null, null, $request, $admin)
            ?->forceFill(['viewed_as_user_id' => $wasViewing])->save();

        return response()->json(['message' => 'Вы снова смотрите портал своими глазами.']);
    }

    /**
     * Права, которые есть у просматриваемого и которых нет у смотрящего.
     *
     * Сторож повышения прав. **Сегодня он не срабатывает ни разу, и это
     * нормально:** право `users.view_as` миграция
     * `2026_08_31_000002_looking_through_someone_eyes_is_its_own_permission`
     * выдаёт ровно роли `admin`, а `hasPermission()` возвращает администратору
     * `true` на что угодно — значит разность всегда пуста. Проверить это можно
     * одной командой, не читая всё приложение.
     *
     * Сторож стоит ради того дня, когда право выдадут кому-то ещё. В тот день
     * никто не вспомнит про эту страницу, а подмена без него стала бы способом
     * получить больше, чем есть.
     *
     * @return list<string>
     */
    private function rightsTheWatcherLacks(User $admin, User $user): array
    {
        $user->load(['role.permissions', 'roles.permissions']);

        $missing = array_values(array_filter(
            $user->permissionCodes(),
            fn (string $code): bool => ! $admin->hasPermission($code),
        ));

        sort($missing);

        return $missing;
    }

    private function refuse(string $message): JsonResponse
    {
        return response()->json(['message' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
