<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Проверка права, объявленного у маршрута.
 *
 * До `ARCH-001`, шага 3, право здесь **выводилось**: из префикса URL и метода
 * по таблице `DOMAIN_RULES`, а при промахе молча возвращалось
 * `reference.manage`. Законный пользователь получал необъяснимое «нет прав», а
 * посторонний с правом на справочники проходил куда угодно. Рядом стояли пять
 * legacy-прав-«зонтиков», открывавших роли целую группу маршрутов без единого
 * конкретного права.
 *
 * Теперь право написано у маршрута, и здесь не осталось ни таблицы, ни разбора
 * пути, ни зависимости от метода:
 *
 * ```php
 * Route::get('students', ...)->middleware('permission:students.view');
 * ```
 *
 * Несколько проверок на одном маршруте складываются через **И** — каждая
 * добавляет требование. Альтернативы внутри одной проверки перечисляются через
 * запятую и складываются через **ИЛИ**:
 *
 * ```php
 * ->middleware('permission:digitalpasses.manage,view_own_data')
 * ```
 */
class EnsurePermission
{
    /**
     * Legacy-права-«зонтики». Ни один маршрут их больше не объявляет, ни одна
     * роль их больше не держит.
     *
     * Список оставлен сторожем: `PermissionTableAndProxyTest` роняет сборку,
     * если зонтик вернётся на маршрут. Без него ошибку заметил бы только
     * пользователь, у которого внезапно открылось лишнее.
     *
     * @var list<string>
     */
    public const LEGACY_UMBRELLAS = [
        'manage_users',
        'manage_dictionaries',
        'manage_schedule',
        'manage_journal',
        'view_reports',
    ];

    /**
     * Право на чтение перекрывается правом на правку: кто может изменить
     * справочник, тот тем более может его прочитать.
     *
     * Без этого перевод чтения на `reference.view` отнял бы чтение у роли,
     * которой выдали правку, но не выдали просмотр, — например у заведённой
     * вручную уже после миграции, раздавшей `reference.view`.
     *
     * @var array<string, string>
     */
    private const COVERED_BY = ['reference.view' => 'reference.manage'];

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        foreach ($this->candidates($permissions) as $candidate) {
            if (Gate::allows('permission', $candidate)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'У вас нет доступа к этому действию.'], Response::HTTP_FORBIDDEN);
    }

    /**
     * Права, любое из которых проходит проверку. Открыто для учёта:
     * инвентаризация обязана считать доступ ровно так же, как его считает сам
     * middleware, иначе она мерит не то.
     *
     * @param  list<string>  $permissions
     * @return list<string>
     */
    public function candidates(array $permissions): array
    {
        $candidates = [];

        foreach ($permissions as $permission) {
            $candidates[] = $permission;

            if (isset(self::COVERED_BY[$permission])) {
                $candidates[] = self::COVERED_BY[$permission];
            }
        }

        return array_values(array_unique(array_filter($candidates)));
    }
}
