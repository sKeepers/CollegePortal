<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Право `mobile.admin.view` не было выдано ни одной роли.
 *
 * Миграция `2026_08_10_142000` завела три права мобильных кабинетов, но выдала
 * только два: считалось, что администратору выдача не нужна — он проходит любую
 * проверку через `Gate::before`, и `auth.can` на фронтенде повторяет то же.
 *
 * Рассуждение верное, вывод неверный. Соседние права выданы явно: `curator` и
 * `teacher` получили свои, а `mobile.student.view` есть у роли `admin` —
 * `RoleSeeder` раздаёт администратору весь каталог. Матрица разрешений вышла
 * несогласованной: три кабинета в ней видно, четвёртый держится на исключении.
 *
 * Цена станет заметна в тот день, когда появится «дежурный» — роль с частью
 * административных прав, но без полной роли `admin`. Кабинет ей не откроется, а
 * причина будет не видна: в матрице право есть, у роли его нет, и выдать его,
 * не зная про исключение, никто не догадается.
 *
 * Идемпотентна.
 */
return new class extends Migration
{
    private const CODE = 'mobile.admin.view';

    private const ROLE = 'admin';

    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id');
        $roleId = DB::table('roles')->where('code', self::ROLE)->value('id');

        if ($permissionId === null || $roleId === null) {
            return;
        }

        $exists = DB::table('permission_role')
            ->where('permission_id', $permissionId)
            ->where('role_id', $roleId)
            ->exists();

        if (! $exists) {
            DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id');
        $roleId = DB::table('roles')->where('code', self::ROLE)->value('id');

        if ($permissionId === null || $roleId === null) {
            return;
        }

        DB::table('permission_role')
            ->where('permission_id', $permissionId)
            ->where('role_id', $roleId)
            ->delete();
    }
};
