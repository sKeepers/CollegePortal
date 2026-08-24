<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `hr.employees.digital_pass.issue` появилось в каталоге 05.08.2026 вместе с
 * выпуском пропуска сотрудника, но только в `RoleSeeder` — миграции для него не
 * завели. На системе, установленной раньше, права не существует, а маршрут
 * `POST employees/{employee}/digital-pass` его требует: кадровик получает `403`,
 * и причина не видна ни в журнале, ни на экране.
 *
 * Ровно тот же случай, что `gate.points.manage` и `students.bulk_accounts`, и
 * найден он тем же способом — сверкой каталога сидера с миграциями.
 */
return new class extends Migration
{
    private const CODE = 'hr.employees.digital_pass.issue';

    /**
     * Кадры и всё.
     *
     * **Здесь стояло «администратор проходит мимо прав через `Gate::before`».
     * Это объясняло не то, что происходит.** Право у администратора есть, и
     * выдала его не эта миграция, а более поздняя догоняющая —
     * `2026_08_23_000001_installed_portal_gets_the_rights_a_new_one_gets`,
     * которая раздала уже стоящему порталу права, какие получает свежий.
     *
     * Правило с тех пор такое: **миграция, заводящая право, той же миграцией
     * выдаёт его администратору** и тем же ролям, что и `RoleSeeder`. Иначе
     * обновлённый портал расходится с установленным. Закреплено
     * `RightsArriveByMigrationTest`.
     *
     * Список здесь не трогаем — миграция давно применена везде, и менять её
     * поведение задним числом незачем. Поправлен только комментарий: в прежнем
     * виде он успел обмануть 24.08.2026 того, кто заводил права на бланки.
     */
    private const ROLES = ['hr'];

    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id');

        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'code' => self::CODE,
                'name' => 'Кадры: выпуск пропуска сотрудника',
                'module' => 'HR',
                'description' => 'Явный выпуск и перевыпуск цифрового пропуска сотрудника.',
                'system' => true,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $existing = DB::table('permission_role')->where('permission_id', $permissionId)->pluck('role_id')->all();

        $rows = DB::table('roles')
            ->whereIn('code', self::ROLES)
            ->pluck('id')
            ->reject(fn (int $roleId): bool => in_array($roleId, $existing, true))
            ->map(fn (int $roleId): array => ['role_id' => $roleId, 'permission_id' => $permissionId])
            ->all();

        if ($rows !== []) {
            DB::table('permission_role')->insert($rows);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id');

        if ($permissionId === null) {
            return;
        }

        DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
