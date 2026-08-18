<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Решение владельца 18.08.2026: учебная часть загружает заявления сама.
 *
 * Заявления приходят книгой Excel из личного кабинета ФИС, а загрузка закрыта
 * правом `import.manage`, которого у роли не было: она вела контингент, а завести
 * его было не из чего — за каждой выгрузкой шли к администратору.
 *
 * `import.manage` — это **только загрузка данных из файлов**. Очистка рабочих
 * данных стенда разведена с ней ещё 10.08.2026 и живёт под `demo_data.manage`,
 * которого роль не получает.
 *
 * Миграция только добавляет: на бою права могли править из интерфейса.
 */
return new class extends Migration
{
    private const CODE = 'import.manage';

    private const ROLES = ['study_records'];

    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id');

        if ($permissionId === null) {
            return;
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

        $roleIds = DB::table('roles')->whereIn('code', self::ROLES)->pluck('id');

        DB::table('permission_role')
            ->where('permission_id', $permissionId)
            ->whereIn('role_id', $roleIds)
            ->delete();
    }
};
