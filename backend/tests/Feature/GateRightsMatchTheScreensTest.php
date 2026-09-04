<?php

namespace Tests\Feature;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Права проходной держат те, кому проходную показывают.
 *
 * Замер 04.09.2026: заместитель и две учебные части держали четыре права
 * проходной, а экраны под ними не пускали — под ролью в режиме просмотра
 * `deputy` получал `/forbidden` на `/access/gate`, `/access/reports` и
 * `/access/muster`. Сервер при этом пускал: он смотрит только на право.
 *
 * Решение владельца: права снять, но «Кто сейчас в здании» и «Отчёты по
 * проходам» открыть директору и заместителю — это лист на случай эвакуации.
 * Отсюда единственное исключение: у заместителя `gate.reports` остаётся.
 *
 * **Проверка перечисляет держателей поимённо, а не считает их.** Число сказало
 * бы, что «стало меньше», и промолчало бы о том, у кого именно. Так уже
 * расходились два перечня, сойдясь в числе.
 */
class GateRightsMatchTheScreensTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, list<string>> право → роли, которым оно положено */
    private const HOLDERS = [
        'gate.scan' => ['admin', 'security'],
        'gate.points.manage' => ['admin', 'security'],
        'digitalpasses.manage' => ['admin', 'security'],
        // Директор и заместитель — по решению владельца, ради списка на случай
        // эвакуации; кадры и проходная — как было.
        'gate.reports' => ['admin', 'deputy', 'director', 'hr', 'security'],
    ];

    public function test_the_gate_rights_are_held_by_those_the_gate_is_shown_to(): void
    {
        $this->seed(RoleSeeder::class);

        foreach (self::HOLDERS as $code => $expected) {
            $this->assertSame($expected, $this->holders($code),
                'Держатели права «'.$code.'» разошлись с решением владельца. Снять лишнего здесь так же легко, как оставить: '
                .'набор заместителя в сидере общий с ролью `academic_office`.');
        }
    }

    /**
     * Заместитель теряет `gate.reports` легче всех, и не по своей вине.
     *
     * Его набор собран той же `academicEditorPermissions()`, что и набор
     * `academic_office`, у которого право снято. Если однажды явную строку в
     * сидере уберут как дубль, красным станет именно это утверждение.
     */
    public function test_the_deputy_keeps_the_muster_list_though_the_set_is_shared(): void
    {
        $this->seed(RoleSeeder::class);

        $this->assertContains('deputy', $this->holders('gate.reports'),
            'Заместитель потерял отчёты проходной вместе с учебной частью: наборы у них общие, право нужно называть явно.');
        $this->assertNotContains('academic_office', $this->holders('gate.reports'),
            'Учебной части отчёты проходной вернулись — общий набор снова раздаёт лишнее.');
    }

    /**
     * Обновление установленного портала снимает права так же, как новая установка.
     *
     * Сидер при обновлении не выполняется никогда. Проверяется настоящим путём
     * боевого: установка сидером, состояние «права были», затем `up()` миграции.
     */
    public function test_the_update_takes_the_rights_away_and_not_only_a_fresh_install(): void
    {
        $this->seed(RoleSeeder::class);

        $given = ['gate.scan', 'gate.points.manage', 'digitalpasses.manage', 'gate.reports'];

        foreach (['deputy', 'study', 'academic_office'] as $role) {
            $roleId = DB::table('roles')->where('code', $role)->value('id');

            foreach (DB::table('permissions')->whereIn('code', $given)->pluck('id') as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }

        $this->assertContains('study', $this->holders('gate.scan'), 'Не удалось воспроизвести состояние «право было».');

        $migration = require database_path('migrations/2026_09_05_000001_the_gate_rights_match_the_screens.php');
        $migration->up();

        foreach (self::HOLDERS as $code => $expected) {
            $this->assertSame($expected, $this->holders($code),
                'После обновления держатели права «'.$code.'» не те, что после установки: два пути разошлись.');
        }
    }

    /** @return list<string> */
    private function holders(string $code): array
    {
        return DB::table('permission_role')
            ->join('roles', 'roles.id', '=', 'permission_role.role_id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('permissions.code', $code)
            ->orderBy('roles.code')
            ->pluck('roles.code')
            ->all();
    }
}
