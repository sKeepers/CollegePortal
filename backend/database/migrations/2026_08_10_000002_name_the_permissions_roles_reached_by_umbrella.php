<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `ARCH-001`, шаг 2: назвать своими правами то, до чего роли дотягивались
 * только через право-зонтик.
 *
 * Замер `permissions:inventory` показал 205 таких пар «роль — маршрут» у
 * четырёх ролей. Состав подтверждён владельцем 10.08.2026.
 *
 * Права на удаление сюда не входят намеренно: удалять может только
 * администратор. Раздел администрирования и правка справочников тоже не
 * выдаются — они остаются у владельца справочников.
 *
 * Правка есть и в `RoleSeeder`, но сидер выполняется только при установке:
 * `installer/update.sh` запускает миграции и ничего больше. Идемпотентна.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const GRANTS = [
        'study' => [
            'admissions.view', 'admissions.edit',
            'students.create', 'students.update',
            'teachers.create', 'teachers.update',
            'groups.create', 'groups.update',
            'graduation.view', 'graduation.edit',
            'frdo.view', 'frdo.export', 'fis.view', 'fis.export',
            'attendance.reports',
            'gate.scan', 'gate.reports', 'gate.points.manage', 'digitalpasses.manage',
        ],
        'deputy' => [
            'admissions.view', 'admissions.edit', 'frdo.export', 'fis.export',
            'gate.scan', 'gate.reports', 'gate.points.manage', 'digitalpasses.manage', 'view_own_data',
        ],
        'academic_office' => [
            'admissions.view', 'admissions.edit', 'frdo.export', 'fis.export',
            'gate.scan', 'gate.reports', 'gate.points.manage', 'digitalpasses.manage', 'view_own_data',
        ],
        'admission' => ['attendance.reports'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $roleCode => $codes) {
            $roleId = DB::table('roles')->where('code', $roleCode)->value('id');

            if ($roleId === null) {
                continue;
            }

            $permissionIds = DB::table('permissions')->whereIn('code', $codes)->pluck('id');
            $existing = DB::table('permission_role')->where('role_id', $roleId)->pluck('permission_id')->all();

            $rows = $permissionIds
                ->reject(fn (int $permissionId): bool => in_array($permissionId, $existing, true))
                ->map(fn (int $permissionId): array => ['role_id' => $roleId, 'permission_id' => $permissionId])
                ->all();

            if ($rows !== []) {
                DB::table('permission_role')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        foreach (self::GRANTS as $roleCode => $codes) {
            $roleId = DB::table('roles')->where('code', $roleCode)->value('id');

            if ($roleId === null) {
                continue;
            }

            DB::table('permission_role')
                ->where('role_id', $roleId)
                ->whereIn('permission_id', DB::table('permissions')->whereIn('code', $codes)->pluck('id'))
                ->delete();
        }
    }
};
