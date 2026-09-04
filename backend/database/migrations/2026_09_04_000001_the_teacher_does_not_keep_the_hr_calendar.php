<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Календарь отсутствий преподавателю больше не положен.
 *
 * Решение владельца 04.09.2026 в двух частях. Первая, общая: **что роли
 * разрешено, то ей и показывается** — перечень путей, повторяющий права, устареет
 * в день следующей выдачи права. Вторая, частная: `/hr/calendar` преподавателю
 * не нужен. Пока право остаётся у роли, эти две части противоречат друг другу:
 * по общему правилу экран пришлось бы показать.
 *
 * **Снимается ровно у `teacher`.** Право держат восемь ролей (замер 04.09.2026:
 * `academic_office`, `admin`, `curator`, `deputy`, `director`, `hr`, `study`,
 * `teacher`), и у остальных семи набор не меняется — это проверяется сторожем с
 * обеих сторон, потому что сузить заодно кому-то ещё здесь легко и на стенде
 * незаметно.
 *
 * **Куратор — не преподаватель, хотя в сидере собран из него.**
 * `RoleSeeder::curator` берёт набор преподавателя и дополняет его; поэтому
 * вместе с этой миграцией `hr.calendar.view` вписан куратору **явно**, иначе он
 * потерял бы календарь молча. И это не теория: куратором назначают карточку
 * преподавателя, а учётная запись у такого человека чаще всего с ролью
 * `teacher`. **Замер на стенде 04.09.2026: групп с назначенным куратором 0 из
 * 58, учётных записей с ролью `curator` — одна.** То есть проверить последствие
 * на живых данных стенда было нечем, и это сказано вслух: если на боевом есть
 * куратор с одной только ролью `teacher`, календарь он потеряет — лечится
 * второй ролью `curator`, права ролей складываются.
 *
 * Идемпотентна: повторный запуск ничего не меняет. `down()` возвращает связь.
 */
return new class extends Migration
{
    private const CODE = 'hr.calendar.view';

    private const ROLE = 'teacher';

    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id');
        $roleId = DB::table('roles')->where('code', self::ROLE)->value('id');

        if ($permissionId === null || $roleId === null) {
            return;
        }

        DB::table('permission_role')
            ->where('role_id', $roleId)
            ->where('permission_id', $permissionId)
            ->delete();
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id');
        $roleId = DB::table('roles')->where('code', self::ROLE)->value('id');

        if ($permissionId === null || $roleId === null) {
            return;
        }

        // insertOrIgnore, а не проверка с последующей вставкой: на PostgreSQL
        // упавший INSERT отравил бы всю транзакцию миграции.
        DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
    }
};
