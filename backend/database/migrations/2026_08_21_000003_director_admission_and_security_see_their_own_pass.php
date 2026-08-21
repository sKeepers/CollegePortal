<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Директор, приёмная комиссия и проходная получают право видеть своё.
 *
 * Раздел «Мой QR-пропуск» открывается правом `view_own_data`. У трёх ролей его
 * не было вовсе, поэтому свой собственный пропуск они не видели — при том что
 * пропуск им выдан и на проходной работает. Особенно нелепо это выглядело у
 * роли `security`: охранник сам ходит через ту же дверь.
 *
 * Право не новое, каталог не меняется — меняется только раздача. Выдаётся
 * ровно `view_own_data`: оно открывает человеку его собственные данные и ничего
 * чужого.
 *
 * Идемпотентна: повторный запуск ничего не добавляет. Миграция только выдаёт
 * право и не выравнивает остальной набор ролей — на боевом сервере права могли
 * править из интерфейса.
 */
return new class extends Migration
{
    private const CODE = 'view_own_data';

    private const ROLES = ['director', 'admission', 'security'];

    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id');

        if ($permissionId === null) {
            return;
        }

        foreach (self::ROLES as $role) {
            $roleId = DB::table('roles')->where('code', $role)->value('id');

            if ($roleId === null) {
                continue;
            }

            // insertOrIgnore, а не проверка с последующей вставкой: на PostgreSQL
            // упавший INSERT отравил бы всю транзакцию миграции.
            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
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
