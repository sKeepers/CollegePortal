<?php

namespace Tests\Feature;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Установка с нуля и обновление обязаны приходить к одному набору прав.
 *
 * Пути у них разные: при установке выполняется `RoleSeeder`, при обновлении —
 * одни миграции. Пока миграция заводит право, но не выдаёт его роли, портал
 * после обновления отличается от свежепоставленного, и отличие всплывает
 * отказом `403` в разделе, который на стенде открывается.
 *
 * Репетиция обновления 23.08.2026 нашла шесть таких расхождений: четыре у
 * администратора, по одному у коменданта и куратора. Здесь закреплено, чтобы
 * седьмого не появилось.
 *
 * `RefreshDatabase` гоняет только миграции — ровно то, что делает
 * `installer/update.sh`. Сидер вызывается явно и только там, где это сказано.
 *
 * Рядом стоит [RolesArriveByMigrationTest] — он про сами роли, этот про права.
 */
class RightsArriveByMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Администратор недосчитывается прав чаще других по устройству сидера: там
     * он получает **все** права разом, поэтому новое право попадает к нему само
     * собой и про выдачу легко забыть. Миграция так не умеет.
     */
    public function test_every_permission_the_migrations_bring_is_granted_to_the_administrator(): void
    {
        $ungranted = DB::table('permissions')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('permission_role')
                    ->join('roles', 'roles.id', '=', 'permission_role.role_id')
                    ->whereColumn('permission_role.permission_id', 'permissions.id')
                    ->where('roles.code', 'admin');
            })
            ->orderBy('code')
            ->pluck('code')
            ->all();

        $this->assertSame(
            [],
            $ungranted,
            'Право заведено миграцией, но администратору не выдано — на обновлённом портале он не увидит раздел, '
            .'который эта же миграция и заводит. Выдайте право миграцией: '.implode(', ', $ungranted),
        );
    }

    /**
     * Сравнение идёт только по правам, которые есть после миграций. Права,
     * заведённые одним сидером, к делу не относятся: они появились при
     * установке и на любой стоящей системе уже есть.
     */
    public function test_the_seeder_adds_no_grant_the_migrations_missed(): void
    {
        $catalogue = DB::table('permissions')->pluck('code')->all();
        $beforeSeeding = $this->grantsLimitedTo($catalogue);

        $this->seed(RoleSeeder::class);

        $afterSeeding = $this->grantsLimitedTo($catalogue);

        $this->assertSame(
            $afterSeeding,
            $beforeSeeding,
            'Установка и обновление расходятся в правах. Связь роли с правом, которую даёт только сидер, '
            .'до обновлённого портала не доедет: выдайте право миграцией тем же ролям.',
        );
    }

    /**
     * @param  array<int, string>  $permissionCodes
     * @return array<int, string>
     */
    private function grantsLimitedTo(array $permissionCodes): array
    {
        return DB::table('permission_role')
            ->join('roles', 'roles.id', '=', 'permission_role.role_id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->whereIn('permissions.code', $permissionCodes)
            ->orderBy('roles.code')
            ->orderBy('permissions.code')
            ->get(['roles.code as role', 'permissions.code as permission'])
            ->map(fn ($row) => $row->role.' -> '.$row->permission)
            ->all();
    }
}
