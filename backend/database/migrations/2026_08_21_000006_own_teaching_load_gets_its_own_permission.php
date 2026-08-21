<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Своя нагрузка открывается своим правом, а не общим «видеть своё».
 *
 * Раздел «Нагрузка» показывался по `view_own_data`. Право это значит «человек
 * видит собственные данные» и выдано почти каждой роли — поэтому пункт меню
 * видели **восемь ролей из тринадцати**: охранник, комендант, приёмная комиссия,
 * кадры, студент. Открывался он у них экраном «У вас нет доступа к этому
 * действию»: своей нагрузки у них нет и быть не может.
 *
 * Владелец заметил это у коменданта 21.08.2026. Заодно заведена проверка
 * `MenuMatchesPermissionsTest`, которая ищет такие расхождения по всем ролям и
 * всем разделам разом — она и запрещает открывать раздел правом «видеть своё».
 *
 * Идемпотентна: повторный запуск ничего не добавляет.
 */
return new class extends Migration
{
    private const CODE = 'teachingload.view_own';

    private const ROLE = 'teacher';

    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id');

        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'module' => 'Study',
                'code' => self::CODE,
                'name' => 'Нагрузка: своя',
                'description' => 'Просмотр собственной учебной нагрузки преподавателем.',
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

        DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
