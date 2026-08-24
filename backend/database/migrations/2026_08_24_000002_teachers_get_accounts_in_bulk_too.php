<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Право на массовую выдачу учётных записей преподавателям.
 *
 * Репетиция первого сентября 24.08.2026 насчитала около 105 шагов руками от
 * пустого портала до открытого журнала, и **шестьдесят из них — один и тот же
 * сброс пароля преподавателю**. У студентов массовая выдача есть, у
 * преподавателей её не было.
 *
 * Роли те же, что у студенческого `students.bulk_accounts`, плюс администратор.
 * **Администратор перечислен явно**, хотя и проходит мимо прав через
 * `Gate::before`: списки здесь обязаны совпадать с `RoleSeeder` до строки, иначе
 * обновлённый портал разойдётся со свежепоставленным. Закреплено
 * `RightsArriveByMigrationTest`.
 */
return new class extends Migration
{
    private const CODE = 'teachers.bulk_accounts';

    private const ROLES = ['admin', 'deputy', 'academic_office', 'study_records'];

    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id');

        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'code' => self::CODE,
                'name' => 'Преподаватели: массовая выдача учетных записей',
                'module' => 'Teachers',
                'description' => 'Создание учетных записей преподавателям разом с одноразовым показом паролей.',
                'system' => true,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $rows = DB::table('roles')
            ->whereIn('code', self::ROLES)
            ->pluck('id')
            ->map(fn (int $roleId): array => ['role_id' => $roleId, 'permission_id' => $permissionId])
            ->all();

        if ($rows !== []) {
            // insertOrIgnore, а не проверка с последующей вставкой: на PostgreSQL
            // упавший INSERT отравил бы всю транзакцию миграции.
            DB::table('permission_role')->insertOrIgnore($rows);
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
