<?php

namespace App\Support\Permissions;

use App\Http\Middleware\EnsurePermission;
use App\Models\Role;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * Кто до чего дотягивается и каким правом.
 *
 * `ARCH-001` требует убрать таблицу префиксов и legacy-права-«зонтики», но
 * сначала нужно знать цену: право-зонтик открывает роли целую группу
 * маршрутов, и снять его вслепую — значит закрыть людям доступ, не понимая,
 * кому именно. Здесь это считается ровно тем же кодом, которым доступ
 * проверяется в бою.
 *
 * Сам класс ничего не меняет: он только смотрит.
 */
class PermissionInventory
{
    public function __construct(private readonly EnsurePermission $middleware)
    {
    }

    /**
     * Строка на каждую пару «роль — маршрут», куда роль проходит.
     *
     * @return list<array{role:string,method:string,uri:string,via:string,concrete:list<string>,umbrella:list<string>}>
     */
    public function reachable(): array
    {
        $roles = $this->rolePermissions();
        $rows = [];

        foreach ($this->guardedRoutes() as $route) {
            $checks = $this->checksFor($route);

            if ($checks === []) {
                continue;
            }

            foreach ($roles as $roleCode => $held) {
                $concrete = [];
                $umbrella = [];
                $passes = true;

                foreach ($checks as $candidates) {
                    $matchedConcrete = array_values(array_intersect($this->concrete($candidates), $held));
                    $matchedUmbrella = array_values(array_intersect($this->umbrellas($candidates), $held));

                    if ($matchedConcrete === [] && $matchedUmbrella === []) {
                        $passes = false;

                        break;
                    }

                    $concrete = [...$concrete, ...$matchedConcrete];
                    $umbrella = [...$umbrella, ...$matchedUmbrella];
                }

                if (! $passes) {
                    continue;
                }

                $onlyUmbrella = $concrete === [] && $umbrella !== [];

                $rows[] = [
                    'role' => $roleCode,
                    'method' => $route->methods()[0],
                    'uri' => $route->uri(),
                    'via' => $onlyUmbrella ? 'umbrella' : 'concrete',
                    'concrete' => array_values(array_unique($concrete)),
                    'umbrella' => array_values(array_unique($umbrella)),
                    // Каким конкретным правом маршрут закрывается — независимо
                    // от того, есть ли оно у роли. Это и есть то, что предстоит
                    // раздать, чтобы снять зонтик.
                    'required' => $this->required($checks),
                ];
            }
        }

        return $rows;
    }

    /**
     * Пары, которые держатся только на праве-зонтике: снимешь его — доступ
     * пропадёт, и это те самые места, по которым нужно решение.
     *
     * @return list<array{role:string,method:string,uri:string,via:string,concrete:list<string>,umbrella:list<string>}>
     */
    public function dependentOnUmbrella(): array
    {
        return array_values(array_filter($this->reachable(), fn (array $row): bool => $row['via'] === 'umbrella'));
    }

    /**
     * Маршруты под проверкой права. Маршруты без неё сюда не попадают: их
     * закрывает что-то другое, и к таблице префиксов они отношения не имеют.
     *
     * @return list<RoutingRoute>
     */
    public function guardedRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn (RoutingRoute $route): bool => $this->permissionMiddleware($route) !== [],
        ));
    }

    /**
     * Для каждой проверки права на маршруте — список прав, любое из которых
     * её проходит. Проверок может быть несколько, и они складываются через И;
     * альтернативы внутри одной перечислены через запятую и складываются
     * через ИЛИ.
     *
     * @return list<list<string>>
     */
    private function checksFor(RoutingRoute $route): array
    {
        return array_map(
            fn (string $spec): array => $this->middleware->candidates(explode(',', $spec)),
            $this->permissionMiddleware($route),
        );
    }

    /** @return list<string> */
    private function permissionMiddleware(RoutingRoute $route): array
    {
        $found = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                $found[] = substr($middleware, strlen('permission:'));
            }
        }

        return $found;
    }

    /** @return array<string, list<string>> */
    private function rolePermissions(): array
    {
        $roles = [];

        foreach (Role::query()->with('permissions')->get() as $role) {
            // Администратор проходит всюду через Gate::before, считать его
            // бессмысленно: он не покажет ни одной зависимости.
            if ($role->code === 'admin') {
                continue;
            }

            $roles[$role->code] = $role->permissions->where('active', true)->pluck('code')->all();
        }

        return $roles;
    }

    /**
     * @param  list<list<string>>  $checks
     * @return list<string>
     */
    private function required(array $checks): array
    {
        $required = [];

        foreach ($checks as $candidates) {
            $required = [...$required, ...$this->concrete($candidates)];
        }

        return array_values(array_unique($required));
    }

    /**
     * @param  list<string>  $candidates
     * @return list<string>
     */
    private function concrete(array $candidates): array
    {
        return array_values(array_diff($candidates, EnsurePermission::LEGACY_UMBRELLAS));
    }

    /**
     * @param  list<string>  $candidates
     * @return list<string>
     */
    private function umbrellas(array $candidates): array
    {
        return array_values(array_intersect($candidates, EnsurePermission::LEGACY_UMBRELLAS));
    }
}
