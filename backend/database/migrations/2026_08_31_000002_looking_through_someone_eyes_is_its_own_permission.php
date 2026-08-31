<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Взгляд глазами человека открывается своим правом, и только администратору.
 *
 * Право заводится **миграцией**, а не только сидером: `installer/update.sh`
 * гоняет миграции и сидер при обновлении не выполняет никогда, поэтому право,
 * объявленное только в `RoleSeeder`, до установленного портала не доезжает — на
 * этом уже терялись `gate.points.manage` и `students.bulk_accounts`. Обратное
 * тоже верно: право, заведённое только миграцией, сотрётся на новой установке,
 * потому что `RoleSeeder` раздаёт права через `sync()`. Поэтому и здесь, и в
 * сидере.
 *
 * Выдаётся ровно роли `admin`, и от этого зависит сторож повышения прав в
 * `ViewAsController`: пока право у одного администратора, смотрящий и так может
 * всё, и подмена ничего не открывает. Расширите список ролей — сторож начнёт
 * работать, для того он и написан.
 *
 * Идемпотентна: повторный запуск ничего не добавляет.
 */
return new class extends Migration
{
    private const CODE = 'users.view_as';

    private const ROLE = 'admin';

    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id');

        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'module' => 'Admin',
                'code' => self::CODE,
                'name' => 'Пользователи: смотреть портал чужими глазами',
                'description' => 'Просмотр портала так, как его видит выбранный человек. Только чтение, без выгрузок.',
                'system' => true,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $roleId = DB::table('roles')->where('code', self::ROLE)->value('id');

        if ($roleId !== null) {
            // insertOrIgnore, а не проверка с последующей вставкой: на PostgreSQL
            // упавший INSERT отравил бы всю транзакцию миграции.
            DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id');

        if ($permissionId === null) {
            return;
        }

        DB::table('users')->whereNotNull('viewing_as_user_id')->update(['viewing_as_user_id' => null]);
        DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
