<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Право на чтение справочников и выдача его всем ролям.
 *
 * Правка есть и в `RoleSeeder`, но сидер выполняется только при установке:
 * `installer/update.sh` запускает миграции и ничего больше. Без этой миграции
 * на уже установленном стенде роли остались бы без права, и выпадающие списки
 * статусов так и молчали бы.
 *
 * Идемпотентна: повторный запуск ничего не дублирует.
 */
return new class extends Migration
{
    private const CODE = 'reference.view';

    public function up(): void
    {
        $now = now();

        $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id');

        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'code' => self::CODE,
                'name' => 'Справочники: просмотр',
                'module' => 'System',
                'description' => 'Чтение нормативно-справочной информации для подписей и фильтров.',
                'system' => true,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $existing = DB::table('permission_role')->where('permission_id', $permissionId)->pluck('role_id')->all();

        $rows = DB::table('roles')
            ->whereNotIn('id', $existing ?: [0])
            ->pluck('id')
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
